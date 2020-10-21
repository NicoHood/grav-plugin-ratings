<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Data\ValidationException;
use Grav\Plugin\Database\PDO;
use Grav\Plugin\Ratings\Ratings;
use Grav\Plugin\Ratings\Rating;
use Grav\Plugin\Ratings\VerificationCodeRepository;

/**
 * Class RatingsPlugin
 * @package Grav\Plugin
 */
class RatingsPlugin extends Plugin
{
    protected $enable = false;

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000], // TODO: Remove when plugin requires Grav >=1.7
                ['register', 1000],
                ['onPluginsInitialized', 1000]
            ]
        ];
    }

    /**
    * Composer autoload.
    *is
    * @return ClassLoader
    */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Register the service
     */
    public function register()
    {
        $this->grav['ratings'] = function ($c) {
            /** @var Config $config */
            $config = $c['config'];

            return new Ratings($config->get('plugins.ratings'));
        };
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            $this->active = false;
            return;
        }

        // TODO check for routes at a very early state?

        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 1000],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onFormProcessed' => ['onVerificationCodeFormProcessed', 0]
        ]);

        // Handle activation token links
        $path = $this->grav['uri']->path();
        if ($path === $this->config->get('plugins.ratings.route_activate')) {
            $this->enable([
                // Second event that subscribes onPagesInitialized
                // to handle email activation token links
                'onPagesInitialized' => ['handleRatingActivation', 0],
            ]);
        }
        if ($path === $this->config->get('plugins.ratings.route_verification_code')) {
            $this->enable([
                // Third event that subscribes onPagesInitialized
                // to handle verification code links
                'onPagesInitialized' => ['handleVerificationCode', 0],
            ]);
        }
    }

    public function onPageInitialized(Event $event)
    {
        // Check if the plugin should be enabled.
        // We need to check this in the page initialized event
        // in order to access the page template property.
        $this->calculateEnable();

        // Enable the main events we are interested in
        if ($this->enable) {
            // NOTE: We must add the form here and not in the onFormPageHeaderProcessed event.
            // The mentioned event will run before onPageInitialized, but we can only validate
            // the page template filters after the page got initialized. Thatswhy the form will be
            // added at this later stage.
            $this->grav['page']->addForms([$this->grav['config']->get('plugins.ratings.form')]);

            $this->enable([
                'onFormValidationProcessed' => ['onFormValidationProcessed', 0],
                'onFormProcessed' => ['onFormProcessed', 0],
                'onTwigSiteVariables' => ['onTwigSiteVariablesWhenActive', 0]
            ]);
        }
    }

    /**
     * Determine if the plugin should be enabled based on the enable_on_routes and disable_on_routes config options
     */
    private function calculateEnable() {
        $uri = $this->grav['uri'];
        $path = $uri->path();
        $page = $this->grav['page'];

        $disable_on_routes = (array) $this->config->get('plugins.ratings.disable_on_routes');
        $enable_on_routes = (array) $this->config->get('plugins.ratings.enable_on_routes');
        $enable_on_templates = (array) $this->config->get('plugins.ratings.enable_on_templates');

        // Make sure the page is available and published
        if(!$page || !$page->published() || !$page->isPage()){
            return;
        }

        // TODO merge configs and then also check for the active flag per page

        // Filter page template
        if (!empty($enable_on_templates)) {
            if (!in_array($this->grav['page']->template(), $enable_on_templates, true)) {
                return;
            }
        }

        // Filter page routes
        if (!in_array($path, $disable_on_routes)) {
            if (in_array($path, $enable_on_routes)) {
                $this->enable = true;
            } else {
                foreach($enable_on_routes as $route) {
                    if (Utils::startsWith($path, $route)) {
                        $this->enable = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     * Before processing the form, make sure that user votes multiple times with the same email.
     *
     * @param Event $event
     */
    public function onFormValidationProcessed(Event $event)
    {
        $language = $this->grav['language'];

        // Special check for rating field
        foreach ($event['form']->getFields() as $field) {
            if ($field['type'] === 'rating') {
                // Get POST data and convert string to int
                $raw_data = $event['form']->value($field['name']);
                $rating_string = filter_var(urldecode($raw_data), FILTER_SANITIZE_NUMBER_INT);
                $rating = filter_var($rating_string, FILTER_VALIDATE_INT);

                // Check if the data is an integer
                if($rating === false) {
                    throw new ValidationException($language->translate('PLUGIN_RATINGS.INVALID_RATING'));
                }

                // Validate minimum and maximum settings
                if(isset($field['validate'])) {
                    if(isset($field['validate']['min']) && $rating < $field['validate']['min']) {
                        throw new ValidationException($language->translate('PLUGIN_RATINGS.INVALID_RATING_MIN'));
                    }
                    if(isset($field['validate']['max']) && $rating > $field['validate']['max']) {
                        throw new ValidationException($language->translate('PLUGIN_RATINGS.INVALID_RATING_MAX'));
                    }
                }
            }
        }

        // The following validation rules only apply to the ratings form, introduced by the ratings plugin.
        if($event['form']->name !== $this->grav['config']->get('plugins.ratings.form')['name']) {
            return;
        }

        // Validate if user is allowed to rate
        $rating = $this->grav['ratings']->getRatingFromPostData($event['form']->data());

        // Validate stars itself
        // NOTE: This is an additional check to the rating field itself, which may have more than 5 stars
        // The rating plugin itself only supports 5 star ratings.
        if($rating->stars < 1) {
            throw new ValidationException($language->translate('PLUGIN_RATINGS.INVALID_RATING_MIN'));
        }
        if($rating->stars > 5) {
            throw new ValidationException($language->translate('PLUGIN_RATINGS.INVALID_RATING_MAX'));
        }

        // Check if user voted for this special page already
        if ($this->grav['ratings']->hasAlreadyRated($rating)) {
            throw new ValidationException($language->translate('PLUGIN_RATINGS.ALREADY_RATED'));
        }

        if ($this->grav['ratings']->hasReachedRatingLimit($rating)) {
            throw new ValidationException($language->translate('PLUGIN_RATINGS.REACHED_RATING_LIMIT'));
        }

        if ($rating->verification_code !== NULL) {
            if ($this->grav['ratings']->isVerificationCodeAlreadyUsed($rating->verification_code)) {
                throw new ValidationException($language->translate('PLUGIN_RATINGS.VERIFICATION_CODE_ALREADY_USED'));
            }

            // Load verification codes from csv file
            // Parameters explained: search schema, return absolut path, return false if file was not found
            $csv_path = 'user-data://ratings/verification_codes.csv';
            $csv_real_path = $this->grav['locator']->findResource($csv_path, true, false);

            // Check if file exists
            if($csv_real_path === false) {
                throw new ValidationException($language->translate('PLUGIN_RATINGS.INVALID_VERIFICATION_CODE'));
            }

            // Load verification code from csv
            $verification_code_repository = new VerificationCodeRepository($csv_real_path);
            $verification_code = $verification_code_repository->getVerificationCode($rating->verification_code);

            if ($verification_code === NULL ||
                $verification_code['code'] !== $rating->verification_code ||
                $verification_code['page'] !== $rating->page) {
                // TODO add delay when code was invalid?
                throw new ValidationException($language->translate('PLUGIN_RATINGS.INVALID_VERIFICATION_CODE'));
            }
        }
    }

    /**
     * Handle form processing instructions.
     *
     * @param Event $event
     */
    public function onFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];
        $params = $event['params'];

        if (!$this->active) {
            return;
        }

        switch ($action) {
            case 'addRating':
                $rating = $this->grav['ratings']->getRatingFromPostData($form->data());

                // Check if there are currently a not yet activated ratings and invalidate those
                $this->grav['ratings']->expireAllRatings($rating->page, $rating->email);

                // Set verified state (must be validated in onFormValidationProcessed event)
                if ($rating->verification_code !== null) {
                    $this->grav['ratings']->expireAllRatingsByVerificationCode($rating->verification_code);
                    $rating->verified = true;
                }

                // Add new rating
                $this->grav['ratings']->addRating($rating);
                break;
        }
    }

    /**
     * Handle form processing instructions.
     *
     * @param Event $event
     */
    public function onVerificationCodeFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];
        $params = $event['params'];

        // TODO If we implement the active flag, the verification processing must be excluded.
        // It would make sense to move the verification functionality into a separate plugin
        if (!$this->active) {
            return;
        }

        switch ($action) {
            case 'processVerificationCode':
                /** @var Message $messages */
                $messages = $this->grav['messages'];

                $code = $form->value('code');
                $this->redirectVerificationCodeToPage($code);
                break;
        }
    }

    private function redirectVerificationCodeToPage(?string $code) : bool {
        $code = preg_replace('/\D/', '', $code);

        /** @var Message $messages */
        $messages = $this->grav['messages'];

        if ($code === NULL) {
            $message = $this->grav['language']->translate('PLUGIN_RATINGS.INVALID_VERIFICATION_CODE');
            $messages->add($message, 'error');
            return false;
        }

        // Check if code was already used
        if ($this->grav['ratings']->isVerificationCodeAlreadyUsed($code)) {
            $message = $this->grav['language']->translate('PLUGIN_RATINGS.VERIFICATION_CODE_ALREADY_USED');
            $messages->add($message, 'error');
            return false;
        }

        // Load verification codes from csv file
        // Parameters explained: search schema, return absolut path, return false if file was not found
        $csv_path = 'user-data://ratings/verification_codes.csv';
        $csv_real_path = $this->grav['locator']->findResource($csv_path, true, false);

        // Check if file exists
        if($csv_real_path === false) {
            return false;
        }

        // Load verification code from csv
        $verification_code_repository = new VerificationCodeRepository($csv_real_path);
        $verification_code = $verification_code_repository->getVerificationCode($code);

        // Code not found
        if ($verification_code === NULL || $verification_code['page'] === NULL) {
            $message = $this->grav['language']->translate('PLUGIN_RATINGS.INVALID_VERIFICATION_CODE');
            $messages->add($message, 'error');
            return false;
        }

        // Redirect to the rated page (Add query string and anchor)
        $redirect_route = $verification_code['page'];
        $redirect_code = null;
        $this->grav->redirectLangSafe($redirect_route . '?code=' . $code . $this->config->get('plugins.ratings.form.anchor'), $redirect_code);
        return true;
    }

    /**
     * Only add twig rating variables when the current page is enabled for being rated.
     */
    public function onTwigSiteVariablesWhenActive() {
        $path = $this->grav['uri']->path();
        $this->grav['twig']->twig_vars['enable_ratings_plugin'] = $this->enable;
        $this->grav['twig']->twig_vars['ratings'] = $this->grav['ratings']->getActiveModeratedRatings($path);
        $this->grav['twig']->twig_vars['rating_results'] = $this->grav['ratings']->getRatingResults($path);
    }

    /**
     * Always add those twig variables and/or assets
     */
    public function onTwigSiteVariables()
    {
        if ($this->config->get('plugins.ratings.built_in_css')) {
            $this->grav['assets']->add('plugin://ratings/css-compiled/ratings.min.css');
        }
    }

    /**
     * Handle rating activation
     */
    public function handleRatingActivation()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        /** @var Message $messages */
        $messages = $this->grav['messages'];

        // URL Parameter
        $token = $uri->param('token');

        try {
            $rating = $this->grav['ratings']->getRatingByToken($token);
        } catch (\RuntimeException $e) {
            $messages->add($e->getMessage(), 'error');
            return;
        }

        // Check for 0 or NULL (Rating already verified or no token verification used at all)
        if ($rating->token_activated()) {
            $message = $this->grav['language']->translate('PLUGIN_RATINGS.RATING_ALREADY_ACTIVATED');
            $messages->add($message, 'warning');
        }
        else if ($this->grav['ratings']->hasAlreadyRated($rating)) {
            $message = $this->grav['language']->translate('PLUGIN_RATINGS.ALREADY_RATED');
            $messages->add($message, 'warning');
        }
        else {
            // Check if token expired. 0 Means unlimited expire time (tokens never expire)
            if ($rating->token_expired()) {
                $message = $this->grav['language']->translate('PLUGIN_RATINGS.TOKEN_EXPIRED');
                $messages->add($message, 'error');
            }
            // Check if some other user has added a rating with the same verification code in the meantime
            else if ($rating->verification_code !== NULL &&
                     $this->grav['ratings']->isVerificationCodeAlreadyUsed($rating->verification_code)) {
                $message = $this->grav['language']->translate('PLUGIN_RATINGS.VERIFICATION_CODE_ALREADY_USED');
                $messages->add($message, 'error');
            }
            else {
                $this->grav['ratings']->activateRating($rating);

                // TODO implement
                // if ($this->config->get('plugins.ratings.send_confirmation_email', false)) {
                //     $this->grav['ratings']->sendConfirmationEmail();
                // }

                $message = $this->grav['language']->translate('PLUGIN_RATINGS.ACTIVATION_SUCCESS');
                $messages->add($message, 'info');
            }
        }

        // Redirect to the rated page
        $redirect_route = $rating->page;
        $redirect_code = null;
        $this->grav->redirectLangSafe($redirect_route ?: '/', $redirect_code);
    }

    /**
     * Handle verification code links
     */
    public function handleVerificationCode()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        // URL Parameter
        $code = $uri->param('code');
        $this->redirectVerificationCodeToPage($code);
    }

    /**
     * Add templates directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }
}

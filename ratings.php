<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Data\Data;
use Grav\Common\Data\ValidationException;
use Grav\Plugin\Database\PDO;
use Grav\Plugin\Ratings\Ratings;
use Grav\Plugin\Ratings\Rating;
use Twig\TwigFunction;

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
    * Composer autoload
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
            return;
        }

        // TODO check for routes at a very early state? -> only then enable onPageInitialized() event
        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 1000],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0]
        ]);

        // Handle activation token links
        $path = $this->grav['route']->getRoute();
        if ($path === $this->config->get('plugins.ratings.route_activate')) {
            $this->enable([
                // Second event that subscribes onPagesInitialized
                // to handle email activation token links
                'onPagesInitialized' => ['handleRatingActivation', 0],
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
        if (!$this->enable) {
            return;
        }

        // NOTE: We must add the form here and not in the onFormPageHeaderProcessed event.
        // The mentioned event will run before onPageInitialized, but we can only validate
        // the page template filters after the page got initialized. Thatswhy the form will be
        // added at this later stage.
        $this->grav['page']->addForms([$this->grav['config']->get('plugins.ratings.form')]);

        $this->enable([
            'onFormValidationProcessed' => ['onFormValidationProcessed', 0],
            'onFormProcessed' => ['onFormProcessed', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariablesWhenActive', 100]
        ]);
    }

    /**
     * Determine if the plugin should be enabled based on the enable_on_routes and disable_on_routes config options
     */
    private function calculateEnable() {
        $path = $this->grav['route']->getRoute();
        $page = $this->grav['page'];

        $disable_on_routes = (array) $this->config->get('plugins.ratings.disable_on_routes');
        $enable_on_routes = (array) $this->config->get('plugins.ratings.enable_on_routes');
        $enable_on_templates = (array) $this->config->get('plugins.ratings.enable_on_templates');

        // Make sure the page is available and published
        if(!$page || !$page->published() || !$page->isPage()){
            return;
        }

        // Merge configs and then also check for the active flag per page
        $config = $this->mergeConfig($page);
        if (!$config->get('active')) {
            return;
        }

        // Filter page template
        if (!empty($enable_on_templates)) {
            if (!in_array($page->template(), $enable_on_templates, true)) {
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
     * Before processing the form, make sure that user does not vote multiple times with the same email.
     *
     * @param Event $event
     */
    public function onFormValidationProcessed(Event $event)
    {
        $language = $this->grav['language'];

        // The following validation rules only apply to the ratings form, introduced by the ratings plugin.
        if($event['form']->name !== $this->grav['config']->get('plugins.ratings.form.name')) {
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

        switch ($action) {
            case 'addRating':
                $rating = $this->grav['ratings']->getRatingFromPostData($form->data());

                // Check if there are currently a not yet activated ratings and invalidate those
                $this->grav['ratings']->expireAllRatings($rating->page, $rating->email);

                // Add new rating
                $this->grav['ratings']->addRating($rating);
                break;
        }
    }

    /**
     * Only add twig rating variables when the current page is enabled for being rated.
     */
    public function onTwigSiteVariablesWhenActive() {
        $page = $this->grav['page'];
        $path = $page->route();

        $this->grav['twig']->twig_vars['enable_ratings_plugin'] = $this->enable;
        $this->grav['twig']->twig_vars['ratings'] = $this->grav['ratings']->getActiveModeratedRatings($path);
        $results = $this->grav['ratings']->getRatingResults($path);
        $this->grav['twig']->twig_vars['rating_results'] = $results;

        // Add SEO structured data
        // TODO this should be moved to onPageProcessed event
        $config = $this->mergeConfig($page);
        $schema = $config->get('add_json_ld', false);
        if($this->enable && $results['count'] > 0 && $schema) {
            // Check plugin dependency
            if (!$this->config->get('plugins.seo.enabled')) {
                throw new \RuntimeException($this->grav['language']->translate('Plugin "seo" not enabled/installed. Unable to add json-ld.'));
            }

            $header = new Data((array)$page->header());

            // Prepare new data for structured-data plugin
            $data = [
              'value' => $results['average'],
              'count' => $results['count'],
              'best' => $results['max'],
              'worst' => $results['min']
            ];

            // The rating can be added as general schema (and referenced via its id)
            // Or the rating can be attached to another schema, like a local_business.
            if ($schema === true) {
                $header->set('seo.aggregate_rating.add_json_ld', true);
                $header->set('structured_data.aggregate_rating', $data);
            }
            else {
                $header->set('structured_data.' . $schema . '.aggregate_rating', $data);
            }

            // Set new header
            $page->header($header->toArray());
        }
    }

    /**
     * Add Twig function
     */
    public function onTwigInitialized()
    {
        $this->grav['twig']->twig()->addFunction(
            new TwigFunction('rating_results', [$this->grav['ratings'], 'getRatingResults'])
        );
    }

    /**
     * Always add those twig variables and/or assets
     */
    public function onTwigSiteVariables()
    {
        if ($this->config->get('plugins.ratings.built_in_css')) {
            if ($this->config->get('plugins.ratings.font_awesome_5', false)) {
                $this->grav['assets']->add('plugin://ratings/css-compiled/ratings-font-awesome-5.min.css');
            }
            else {
                $this->grav['assets']->add('plugin://ratings/css-compiled/ratings.min.css');
            }
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
     * Add templates directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }
}

<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Data\ValidationException;
use Grav\Plugin\Database\PDO;
use Grav\Plugin\Ratings\Ratings;

/**
 * Class RatingsPlugin
 * @package Grav\Plugin
 */
class RatingsPlugin extends Plugin
{
    protected $enable = false;
    protected $ratings_cache_id;

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
                ['onPluginsInitialized', 1000],
                ['register', 1000]
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

    public function onTwigSiteVariables() {
        $this->grav['twig']->twig_vars['enable_ratings_plugin'] = $this->enable;
        $this->grav['twig']->twig_vars['ratings'] = $this->fetchRatings();
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
              dump('no rating');
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
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        // TODO check for routes at a very early state?

        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0]
        ]);

        $cache = $this->grav['cache'];
        $uri = $this->grav['uri'];

        //init cache id
        $this->ratings_cache_id = md5('ratings-data' . $cache->getKey() . '-' . $uri->url());
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
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
            ]);
        }
    }

    /**
     * Before processing the form, make sure that user votes multiple times with the same email.
     *
     * @param Event $event
     */
    public function onFormValidationProcessed(Event $event)
    {
        // Special check for rating field
        foreach ($event['form']->fields() as $field) {
            if ($field['type'] === 'rating') {
                // Get POST data and convert string to int
                $raw_data = $event['form']->value($field['name']);
                $rating_string = filter_var(urldecode($raw_data), FILTER_SANITIZE_NUMBER_INT);
                $rating = filter_var($rating_string, FILTER_VALIDATE_INT);

                // Check if the data is an integer
                if($rating === false) {
                    throw new ValidationException('Invalid rating passed.');
                }

                // Validate minimum and maximum settings
                if(isset($field['validate'])) {
                    if(isset($field['validate']['min']) && $rating < $field['validate']['min']) {
                        throw new ValidationException('Rating is below minimum.');
                    }
                    if(isset($field['validate']['max']) && $rating > $field['validate']['max']) {
                        throw new ValidationException('Rating is above maximum.');
                    }
                }
            }
        }

        // Validate if user is allowed to rate
        $post = isset($_POST['data']) ? $_POST['data'] : [];
        $path = $this->grav['uri']->path();
        $email = filter_var(urldecode($post['email']), FILTER_SANITIZE_STRING);

        if (isset($this->grav['user'])) {
            $user = $this->grav['user'];
            if ($user->authenticated) {
                $email = $user->email;
            }
        }

        // Check if user voted for this special page already
        if ($this->grav['ratings']->hasAlreadyRated($path, $email)) {
            throw new ValidationException('You have already voted for this post.');
        }

        if ($this->grav['ratings']->hasReachedRatingLimit($email)) {
            throw new ValidationException('You have already voted on too many topics.');
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
                $post = isset($_POST['data']) ? $_POST['data'] : [];

                $path = $this->grav['uri']->path();
                $title = $this->grav['page']->title();

                $text = filter_var(urldecode($post['text']), FILTER_SANITIZE_STRING);
                $name = filter_var(urldecode($post['name']), FILTER_SANITIZE_STRING);
                $email = filter_var(urldecode($post['email']), FILTER_SANITIZE_STRING);
                $rating = (int) filter_var(urldecode($post['rating']), FILTER_SANITIZE_NUMBER_INT);

                $moderated = 0;
                if (!$this->grav['config']->get('plugins.ratings.moderation')) {
                    $moderated = 1;
                }

                if (isset($this->grav['user'])) {
                    $user = $this->grav['user'];
                    if ($user->authenticated) {
                        $name = $user->fullname;
                        $email = $user->email;
                    }
                }

                $this->grav['ratings']->addRating($rating, $path, $email, $name, $text);

                // Clear cache
                $this->grav['cache']->delete($this->ratings_cache_id);

                break;
        }
    }

    /**
     * Check if a specified rating is moderated.
     * If moderation is not enabled in the settings,
     * moderation will be always true.
     * @param array $rating The rating to check its moderated state
     * @return bool True if rating is moderated and should be visible to the user.
     */
    public function isModerated($rating)
    {
        if (!$this->grav['config']->get('plugins.ratings.moderation')) {
            $rating['moderated'] = 1;
            return true;
        }

        if (!isset($rating['moderated'])) {
            $rating['moderated'] = 0;

            // TODO return false? https://github.com/getgrav/grav-plugin-guestbook/commit/21d8d74266facc132a12f368b6b3dd46c930d636#r42406354
            return $this->isModerated($rating);
        } elseif ($rating['moderated'] == 0) {
            return false;
        } elseif ($rating['moderated'] == 1) {
            return true;
        }

        return false;
    }

    /**
     * Return the ratings associated to the current route
     */
    private function fetchRatings() {
        $cache = $this->grav['cache'];

        // Search in cache
        if ($ratings = $cache->fetch($this->ratings_cache_id)) {
            return $ratings;
        }

        $path = $this->grav['uri']->path();
        $data = $this->grav['ratings']->getRatings($path);

        // Filter out not yet moderated ratings
        // TODO move to database function?
        $moderated = [];
        foreach ($data as $value) {
            if ($this->isModerated($value)) {
                $moderated[] = $value;
            }
        }
        $ratings = $moderated;

        // Save to cache if enabled
        $cache->save($this->ratings_cache_id, $ratings);
        return $ratings;
    }

    /**
     * Add templates directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }
}

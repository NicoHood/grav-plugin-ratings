<?php

declare(strict_types=1);

namespace Grav\Plugin\Ratings;

use Grav\Common\Grav;
use Grav\Common\Config\Config;
use Grav\Common\Filesystem\Folder;
use Grav\Plugin\Database\PDO;
use Grav\Plugin\Email\Utils as EmailUtils;

class Ratings
{
    /** @var Grav */
    protected $grav;

    /** @var Language $language */
    protected $language;

    /** @var Cache $cache */
    protected $cache;

    /** @var Config $config */
    protected $config;
    protected $path = 'user-data://ratings';
    protected $db_name = 'ratings.db';

    /** @var RatingRepository */
    protected $rating_repository;

    public function __construct($config)
    {
        $this->grav = Grav::instance();
        $this->language = $this->grav['language'];
        $this->cache = $this->grav['cache'];

        $this->config = new Config($config);
        $db_path = $this->grav['locator']->findResource($this->path, true, true);

        // Create dir if it doesn't exist
        if (!file_exists($db_path)) {
            Folder::create($db_path);
        }

        $connect_string = 'sqlite:' . $db_path . '/' . $this->db_name;
        $this->rating_repository = new RatingRepository($this->grav['database'], $connect_string);
    }

    public function addRating(Rating $rating) {
        $this->rating_repository->create($rating);

        // Clear cache
        $cache_id = $this->getRatingsCacheId($rating->page);
        $this->cache->delete($cache_id);
        $cache_id = $this->getRatingResultsCacheId($rating->page);
        $this->cache->delete($cache_id);

        if($rating->token) {
            $this->sendActivationEmail($rating);
        }
    }

    public function getRatingByToken(string $token) {
        $results = $this->rating_repository->findToken($token);

        if(count($results) > 1) {
            throw new \RuntimeException($this->language->translate('PLUGIN_RATINGS.MULTIPLE_TOKENS_FAILURE'));
        }

        if(count($results) < 1) {
            throw new \RuntimeException($this->language->translate('PLUGIN_RATINGS.TOKEN_NOT_FOUND'));
        }
        return $results[0];
    }

    public function activateRating(Rating $rating) : Rating {
        $rating->set_token_activated();
        $this->rating_repository->update($rating);

        // Clear cache
        $cache_id = $this->getRatingsCacheId($rating->page);
        $this->cache->delete($cache_id);
        $cache_id = $this->getRatingResultsCacheId($rating->page);
        $this->cache->delete($cache_id);

        return $rating;
    }

    /**
     * Handle the email to activate the user rating.
     *
     * @param \Rating
     *
     * @return bool True if the action was performed.
     * @throws \RuntimeException
     */
    protected function sendActivationEmail($rating)
    {
        // Make sure to use the system wide and not the plugin configuration
        $system_config = $this->grav['config'];
        $param_sep = $system_config->get('system.param_sep', ':');
        $activation_link = $this->grav['base_url_absolute'] . $this->config->get('route_activate') . '/token' . $param_sep . $rating->token;

        $site_name = $system_config->get('site.title', $this->grav['base_url_absolute']);
        $subject = $this->language->translate(['PLUGIN_RATINGS.ACTIVATION_EMAIL_SUBJECT', $site_name]);

        /** @var Email $email */
        $email = $this->grav['Email'];

        $params = [
            'subject' => $subject,
            'body' => '',
            'template' => 'email/activate_rating.html.twig',
            'to' => $rating->email,
            'to_name' => $rating->author,
            'content_type' => 'text/html'];

        $template_vars = [
          'rating' => $rating,
          'activation_link' => $activation_link
        ];

        $message = $email->buildMessage($params, $template_vars);
        $sent = $email->send($message);

        if ($sent < 1) {
            throw new \RuntimeException($this->language->translate('PLUGIN_RATINGS.EMAIL_SENDING_FAILURE'));
        }

        return true;
    }

    public function expireAllRatings(string $page, string $email) {
        $ratings = $this->rating_repository->find($page, $email);

        foreach ($ratings as $rating) {
            $rating->set_expired();
            $this->rating_repository->update($rating);
        }
    }

    public function getActiveModeratedRatings(string $page) {
        // Search in cache
        $cache_id = $this->getRatingsCacheId($page);
        if ($ratings = $this->cache->fetch($cache_id)) {
            return $ratings;
        }

        $ratings = $this->rating_repository->find($page);

        // Filter not moderated ratings
        $ratings = array_filter($ratings, function(Rating $rating) : bool {
            return $rating->moderated;
        });

        // Only allow activated ratings.
        $ratings = array_filter($ratings, function(Rating $rating) : bool {
            return $rating->token_activated();
        });

        // Save to cache if enabled
        $this->cache->save($cache_id, $ratings);
        return $ratings;
    }

    public function hasAlreadyRated(Rating $rating) : bool {
        $ratings = $this->rating_repository->find($rating->page, $rating->email);

        // Only allow activated ratings.
        // NOTE: We also count not yet moderated ratings.
        $ratings = array_filter($ratings, function(Rating $rating) : bool {
            return $rating->token_activated();
        });
        return count($ratings) > 0;
    }

    public function hasReachedRatingLimit(Rating $rating) : bool {
        // Skip if there is no limit
        // NOTE: Use a simple check (== instead of ===) as the setting may be null.
        $limit = $this->config->get('rating_pages_limit');
        if ($limit == 0) {
            return false;
        }

        // Get all rating from this user/email
        $ratings = $this->rating_repository->find(null, $rating->email);

        // NOTE: We also count not yet moderated ratings.
        // NOTE: We also count not yet activated rating
        $ratings = array_filter($ratings, function(Rating $existing_rating) use($rating) : bool {
            // Only if a user wants to vote on the same page, that has not been activated
            // filter this out. He will have the chance to vote again on this page.
            if ($existing_rating->page === $rating->page && !$existing_rating->token_activated()) {
                return false;
            }

            // But we do filter out expired tokens
            return !$existing_rating->token_expired() || $existing_rating->token_activated();
        });

        return count($ratings) >= $limit;
    }

    // $post should be from $event['form']->data()
    // TODO test what happens if some post data is missing
    public function getRatingFromPostData($post) : Rating {
        $rating = new Rating();

        $rating->page = $this->grav['page']->route();
        $rating->email = strtolower(strip_tags(urldecode($post['email'])));
        $rating->author = strip_tags(urldecode($post['name']));
        $rating->date = time();
        $rating->title = $post['title'] ? strip_tags(urldecode($post['title'])) : NULL;
        $rating->review = $post['review'] ? strip_tags(urldecode($post['review'])) : NULL;
        $rating->stars = (int) filter_var(urldecode($post['stars']), FILTER_SANITIZE_NUMBER_INT);
        // NOTE: system.languages.supported must be set in order to get a correct language.
        if($this->language->enabled()) {
            $rating->lang = $this->language->getLanguage();
        }
        $rating->moderated = !$this->grav['config']->get('moderation');

        // Get email and author from grav login (ignore POST data)
        if (isset($this->grav['user'])) {
            $user = $this->grav['user'];
            if ($user->authenticated) {
                $rating->author = $user->fullname;
                $rating->email = $user->email;
            }
        }

        // Calculate expire date
        if ($this->config->get('email_verification')) {
            $expire_time = (int) $this->config->get('activation_token_expire_time', 604800);
            $rating->set_expire_time($expire_time);
        }

        return $rating;
    }

    public function getRatingResults(string $page) {
        // Search in cache
        $cache_id = $this->getRatingResultsCacheId($page);
        if ($results = $this->cache->fetch($cache_id)) {
            return $results;
        }

        // Get all ratings for this page
        $ratings = $this->getActiveModeratedRatings($page);

        $min = 1;
        $max = 5;
        $count = count($ratings);
        $stars = array_fill($min, $max, 0);

        // Calculate average
        $sum = 0;
        foreach ($ratings as $rating) {
            $sum += $rating->stars;
            $stars[$rating->stars] += 1;
        }
        $average = $count === 0 ? 0 : round(($sum / $count), 1);

        // Calculate average rounded to the next half star (e.g. 3.3 -> 3.5)
        $average_rounded = round($average * 2) / 2;

        $results = [
            "min" => $min,
            "max" => $max,
            "count" => (int) $count,
            "average" => (float) $average,
            "average_rounded" => (float) $average_rounded,
            "1" => (int) $stars[1],
            "2" => (int) $stars[2],
            "3" => (int) $stars[3],
            "4" => (int) $stars[4],
            "5" => (int) $stars[5]
        ];

        // Save to cache if enabled
        $this->cache->save($cache_id, $results);
        return $results;
    }

    protected function getRatingsCacheId($page) {
        // Cache key allows us to invalidate all cache on configuration changes.
        return hash('sha256', 'ratings-data' . $this->cache->getKey() . '-' . $page);
    }

    protected function getRatingResultsCacheId($page) {
        // Cache key allows us to invalidate all cache on configuration changes.
        return hash('sha256', 'ratings-results' . $this->cache->getKey() . '-' . $page);
    }
}

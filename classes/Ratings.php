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

    protected $config;
    protected $path = 'user-data://ratings';
    protected $db_name = 'ratings.db';

    /** @var RatingRepository */
    protected $rating_repository;

    public function __construct($config)
    {
        $this->grav = Grav::instance();
        $this->language = $this->grav['language'];

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

        $site_name = $system_config->get('site.title', 'Website');
        $site_link = $this->grav['base_url_absolute'];
        $author = $system_config->get('site.author.name', '');
        $fullname = $rating->author;

        $subject = $this->language->translate(['PLUGIN_RATINGS.ACTIVATION_EMAIL_SUBJECT', $site_name]);
        $content = $this->language->translate(['PLUGIN_RATINGS.ACTIVATION_EMAIL_BODY',
            $fullname,
            $activation_link,
            $site_name,
            $author,
            $site_link
        ]);
        $to = $rating->email;
        $sent = EmailUtils::sendEmail($subject, $content, $to);

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
        $ratings = $this->rating_repository->find($page);

        // Filter not moderated ratings
        $ratings = array_filter($ratings, function(Rating $rating) : bool {
            return $rating->moderated;
        });

        // Only allow activated ratings.
        $ratings = array_filter($ratings, function(Rating $rating) : bool {
            return $rating->token_activated();
        });
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

    public function hasReachedRatingLimit($email) : bool {
        // Skip if there is no limit
        // NOTE: Use a simple check (== instead of ===) as the setting may be null.
        $limit = $this->config->get('rating_pages_limit');
        if ($limit == 0) {
            return false;
        }

        // Get all rating from this user/email
        $ratings = $this->rating_repository->find(null, $email);

        // NOTE: We also count not yet moderated ratings.
        // NOTE: We also count not yet activated rating
        $ratings = array_filter($ratings, function(Rating $rating) : bool {
            // But we do filter out expired tokens
            return !$rating->token_expired();
        });

        return count($ratings) >= $limit;
    }

    // $post should be from $event['form']->data()
    // TODO test what happens if some post data is missing
    public function getRatingFromPostData($post) : Rating {
        $rating = new Rating();

        $rating->page = $this->grav['uri']->path();
        $rating->review = filter_var(urldecode($post['text']), FILTER_SANITIZE_STRING);
        $rating->author = filter_var(urldecode($post['name']), FILTER_SANITIZE_STRING);
        $rating->email = filter_var(urldecode($post['email']), FILTER_SANITIZE_STRING);
        $rating->stars = (int) filter_var(urldecode($post['stars']), FILTER_SANITIZE_NUMBER_INT);
        $rating->moderated = !$this->grav['config']->get('moderation');
        $rating->lang = $this->grav['language']->getLanguage();
        // TODO date currently set automatically

        // Get email and author from grav login (ignore POST data)
        if (isset($this->grav['user'])) {
            $user = $this->grav['user'];
            if ($user->authenticated) {
                $rating->author = $user->fullname;
                $rating->email = $user->email;
            }
        }

        // Calculate expire date
        $expire_time = (int) $this->config->get('activation_token_expire_time', 604800);
        $rating->set_expire_time($expire_time);

        return $rating;
    }
}

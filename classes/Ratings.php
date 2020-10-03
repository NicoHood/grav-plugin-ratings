<?php

declare(strict_types=1);

namespace Grav\Plugin\Ratings;

use Grav\Common\Grav;
use Grav\Common\Config\Config;
use Grav\Plugin\Database\PDO;
use Grav\Plugin\Email\Utils as EmailUtils;

class Ratings
{
    /** @var Grav */
    protected $grav;

    /** @var Language $language */
    protected $language;

    /** @var PDO */
    protected $db;

    protected $config;
    protected $path = 'user-data://ratings';
    protected $db_name = 'ratings.db';

    // Tables
    protected $table_ratings = 'ratings';

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

        $this->db = $this->grav['database']->connect($connect_string);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // if (!$this->db->tableExists($this->table_ratings)) {
        //     $this->createTables();
        // }

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

    public function removeInactivatedRatings(string $page) {
        // If there are other ratings on the same page/email with pending tokens, delete them now.
        $query = "DELETE FROM {$this->table_ratings}
          WHERE page = :page
          AND expire IS NOT NULL";
        $statement = $this->db->prepare($query);
        $statement->bindValue(':page', $page, PDO::PARAM_STR);
        $statement->execute();
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

    public function getRating(string $page, string $email) {
        return $this->rating_repository->find($page, $email);
    }

    public function expireRating(Rating $rating) {
        $rating->set_expired();
        $this->rating_repository->update($rating);
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

    // TODO rename to getActiveModeratedRatings
    // TODO also check moderated state, and make sure the rating is active
    public function getRatings(string $page, string $email = NULL, int $stars = NULL) {
        // Make sure to only count activated tokens (expire == NULL)
        // If a rating was not yet verified,
        // treat this as if the user did not vote on this page.
        $query = "SELECT stars, page, email, author, review, date, moderated
          FROM {$this->table_ratings}
          WHERE page = :page
          AND expire IS NULL";

        if (null !== $email) {
            $query .= ' AND email = :email';
        }
        if (null !== $stars) {
            $query .= ' AND stars = :stars';
        }

        $statement = $this->db->prepare($query);
        if (null !== $email) {
            $statement->bindValue(':email', $email, PDO::PARAM_STR);
        }
        if (null !== $stars) {
            $statement->bindValue(':stars', $stars, PDO::PARAM_INT);
        }
        $statement->bindValue(':page', $page, PDO::PARAM_STR);
        $statement->execute();

        // We want only the associated values e.g.: 'stars' -> 5
        // instead of also having array indexes: 0 -> 5
        $results = $statement->fetchAll(PDO::FETCH_ASSOC);

        // If there a no results, an empty arra [] will be returned.
        return $results;
    }

    public function hasAlreadyRated(Rating $rating) : bool {
        $page = $rating->page;
        $email = $rating->email;

        // Make sure to only count activated tokens (expire is NULL)
        // If a rating was not yet verified,
        // treat this as if the user did not vote on this page.
        $query = "SELECT EXISTS(
          SELECT 1 FROM {$this->table_ratings}
          WHERE page = :page
          AND email = :email
          AND expire IS NULL
          LIMIT 1)";
        $statement = $this->db->prepare($query);
        $statement->bindValue(':page', $page, PDO::PARAM_STR);
        $statement->bindValue(':email', $email, PDO::PARAM_STR);
        $statement->execute();
        $result = $statement->fetchColumn();

        // NOTE: we are doing a lazy check here (== instead of ===),
        // as the database will return a string instead of an int or bool.
        return $result == "1" ? true : false;
    }

    public function hasReachedRatingLimit($email) : bool {
        // Skip if there is no limit
        // NOTE: Use a simple check (== instead of ===) as the setting may be null.
        $limit = $this->config->get('rating_pages_limit');
        if ($limit == 0) {
            return false;
        }

        // Make sure to only count activated tokens (expire is NULL)
        // If a rating was not yet verified,
        // treat this as if the user did not vote on this page.
        $query = "SELECT COUNT(DISTINCT page)
          FROM {$this->table_ratings}
          WHERE email = :email
          AND expire is NULL";
        $statement = $this->db->prepare($query);
        $statement->bindValue(':email', $email, PDO::PARAM_STR);
        $statement->execute();
        $result = $statement->fetchColumn();

        // NOTE: we are doing a lazy check here (>= instead of >==),
        // as the database will return a string instead of an int.
        return $result >= $limit ? true : false;
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

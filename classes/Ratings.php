<?php

declare(strict_types=1);

namespace Grav\Plugin\Ratings;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Config\Config;
use Grav\Plugin\Database\PDO;
use Grav\Plugin\Email\Utils as EmailUtils;

class Rating
{
    public int $id;
    public string $page;
    public string $email;
    public string $author;
    public int $stars;
    public string $review;
    public bool $moderated = true;
    public ?string $token = null;

    // Absolut time when the token expires
    // 0 Means it never expires
    // NULL means the token was validated
    public ?int $expire = NULL;
    public string $lang;

    // TODO add date

    function token_expired(): bool {
        if ($this->expire === 0 || $this->expire === NULL) {
            return false;
        }
        else {
            return time() > $this->expire;
        }
    }

    function set_expire_time(int $expire_time) {
        $this->token = md5(uniqid((string)mt_rand(), true));

        // Do not calculate an expire date if unlimited expire time was choosen (expire time is 0)
        if ($expire_time === 0) {
            $this->expire = 0;
        }
        else {
            $this->expire = time() + $expire_time;
        }
    }

    function set_expired() {
        // Since 0 means never expires, 1 will mark an expired rating
        // (1 is always < time())
        $this->expire = 1;
    }

    function token_activated():bool {
        return $this->expire === NULL;
    }

    function set_token_activated() {
        $this->expire = NULL;
    }
}

// Pattern borrowed from: http://slashnode.com/pdo-for-elegant-php-database-access/
class RatingRepository
{
    /** @var PDO */
    protected $db;

    // Tables
    protected $table_ratings = 'ratings';

    public function __construct($database, $connect_string)
    {
        $this->db = $database->connect($connect_string);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (!$this->db->tableExists($this->table_ratings)) {
            $this->createTables();
        }
    }

    // TODO improve class parameter?
    // TODO add return type
    public function create($rating) {
        // If the ID is set, we're updating an existing record
        if (isset($rating->id)) {
            return $rating->update($user);
        }

        // TODO date currently set automatically
        $query = "INSERT INTO {$this->table_ratings}
          (page, email, author, stars, review, moderated, token, expire, lang, date)
          VALUES
          (:page, :email, :author, :stars, :review, :moderated, :token, :expire, :lang, datetime('now', 'localtime'))";

        $statement = $this->db->prepare($query);
        $statement->bindValue(':page', $rating->page, PDO::PARAM_STR);
        $statement->bindValue(':email', $rating->email, PDO::PARAM_STR);
        $statement->bindValue(':author', $rating->author, PDO::PARAM_STR);
        $statement->bindValue(':stars', $rating->stars, PDO::PARAM_INT);
        $statement->bindValue(':review', $rating->review, PDO::PARAM_STR);
        $statement->bindValue(':moderated', $rating->moderated, PDO::PARAM_BOOL);
        $statement->bindValue(':token', $rating->token, PDO::PARAM_STR);
        $statement->bindValue(':expire', $rating->expire, PDO::PARAM_INT);
        $statement->bindValue(':lang', $rating->lang, PDO::PARAM_STR);

        $statement->execute();

        // TODO Is the following thread safe???
        // https://stackoverflow.com/questions/2127138/how-to-retrieve-the-last-autoincremented-id-from-a-sqlite-table
        $query = "SELECT seq FROM 'sqlite_sequence' WHERE name = '{$this->table_ratings}'";
        $statement = $this->db->prepare($query);
        $statement->execute();
        $id = (int) $statement->fetchColumn();

        // Safe new rating id
        $rating->id = $id;
        return $rating;
    }

    // TODO specify return type
    public function read(int $id) {
        $query = "SELECT *
          FROM {$this->table_ratings}
          WHERE id = :id";

        $statement = $this->db->prepare($query);
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

        // TODO this will return an array?
        $results = $statement->fetchAll(PDO::FETCH_CLASS, Rating::class);
        return $results;
    }

    public function update($rating) {
        if (!isset($rating->id)) {
            // We can't update a record unless it exists...
            throw new \LogicException(
                'Cannot update a rating that does not yet exist in the database.'
            );
        }

        // TODO date missing here
        $query = "UPDATE {$this->table_ratings}
          SET page = :page,
              email = :email,
              author = :author,
              stars = :stars,
              review = :review,
              moderated = :moderated,
              token = :token,
              expire = :expire,
              lang = :lang
          WHERE id = :id";
        $statement = $this->db->prepare($query);
        $statement->bindValue(':id', $rating->id, PDO::PARAM_STR);
        $statement->bindValue(':page', $rating->page, PDO::PARAM_STR);
        $statement->bindValue(':email', $rating->email, PDO::PARAM_STR);
        $statement->bindValue(':author', $rating->author, PDO::PARAM_STR);
        $statement->bindValue(':stars', $rating->stars, PDO::PARAM_INT);
        $statement->bindValue(':review', $rating->review, PDO::PARAM_STR);
        $statement->bindValue(':moderated', $rating->moderated, PDO::PARAM_BOOL);
        $statement->bindValue(':token', $rating->token, PDO::PARAM_STR);
        $statement->bindValue(':expire', $rating->expire, PDO::PARAM_INT);
        $statement->bindValue(':lang', $rating->lang, PDO::PARAM_STR);

        $statement->execute();
    }

    public function findToken(string $token) {
        $query = "SELECT *
          FROM {$this->table_ratings}
          WHERE token = :token";
        $statement = $this->db->prepare($query);
        $statement->bindValue(':token', $token, PDO::PARAM_STR);
        $statement->execute();

        $results = $statement->fetchAll(PDO::FETCH_CLASS, Rating::class);
        return $results;
    }

    public function find(string $page, ?string $email = null, ?int $stars = null) {
        $query = "SELECT *
          FROM {$this->table_ratings}
          WHERE page = :page";

        if (null !== $email) {
            $query .= ' AND email = :email';
        }
        if (null !== $stars) {
            $query .= ' AND stars = :stars';
        }
        $statement = $this->db->prepare($query);
        $statement->bindValue(':page', $page, PDO::PARAM_STR);
        if (null !== $email) {
            $statement->bindValue(':email', $email, PDO::PARAM_STR);
        }
        if (null !== $stars) {
            $statement->bindValue(':stars', $stars, PDO::PARAM_INT);
        }
        $statement->execute();

        $results = $statement->fetchAll(PDO::FETCH_CLASS, Rating::class);
        return $results;
    }

    public function createTables()
    {
        $commands = [
            // NOTE: Autoincrement is somehow special in sqlite:
            // https://stackoverflow.com/questions/7905859/is-there-an-auto-increment-in-sqlite
            // NOTE: If expire is NULL the rating is activated. If expire is 0, the token will never expire.
            "CREATE TABLE IF NOT EXISTS {$this->table_ratings} (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              page VARCHAR(255) NOT NULL,
              stars INTEGER DEFAULT 0 NOT NULL,
              email VARCHAR(255) NOT NULL,
              author VARCHAR(255) NOT NULL,
              review TEXT NOT NULL,
              date TEXT NOT NULL,
              lang VARCHAR(255) NOT NULL,
              token VARCHAR(255) DEFAULT NULL,
              expire INTEGER DEFAULT NULL,
              moderated BOOL DEFAULT TRUE)",
        ];
        // TODO add SQL CONSTRAINT to limit starss to 1-5? -> use config value

        // execute the sql commands to create new tables
        foreach ($commands as $command) {
            $this->db->exec($command);
        }
    }

    protected function supportOnConflict()
    {
        static $bool;

        if ($bool === null) {
            $query = $this->db->query('SELECT sqlite_version()');
            $version = $query ? $query->fetch()[0] ?? 0 : 0;
            $bool = version_compare($version, '3.24', '>=');
        }

        return $bool;
    }
}

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

    public function addRating(int $stars, string $page, string $email, string $author, string $review) {
        $rating = new Rating();
        $rating->stars = $stars;
        $rating->page = $page;
        $rating->email = $email;
        $rating->author = $author;
        $rating->review = $review;

        $rating->lang = $this->grav['language']->getLanguage();

        $expire_time = (int) $this->config->get('activation_token_expire_time', 604800);
        $rating->set_expire_time($expire_time);

        // TODO date currently set automatically

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

    // TODO add function param class type
    public function activateRating($rating) {
        $rating->set_token_activated();
        $this->rating_repository->update($rating);
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

    // TODO rename plural
    public function expireRating($rating) {
        $rating->set_expired();
        $this->rating_repository->update($rating);
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

    public function hasAlreadyRated($page, $email) {
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

    public function hasReachedRatingLimit($email) {
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
}

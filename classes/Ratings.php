<?php

namespace Grav\Plugin\Ratings;

use Grav\Common\Filesystem\Folder;
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

        if (!$this->db->tableExists($this->table_ratings)) {
            $this->createTables();
        }
    }

    public function addRating(int $rating, string $page, string $email, string $author, string $review) {

        // TODO check if adding this rating is even allowed (check voting limits)

        $lang = $this->grav['language']->getLanguage();

        // Generate a new token for email verification
        $activation_token = NULL;
        if ($this->config->get('email_verification', false)) {
            $token = md5(uniqid(mt_rand(), true));
            $expire = time() + $this->config->get('activation_token_expire_time', 604800);
            $activation_token = $token . '::' . $expire;
        }

        $query = "INSERT INTO {$this->table_ratings}
          (page, rating, email, author, review, date, lang, token)
          VALUES
          (:page, :rating, :email, :author, :review, datetime('now', 'localtime'), :lang, :token)";

        $statement = $this->db->prepare($query);
        $statement->bindValue(':rating', $rating, PDO::PARAM_INT);
        $statement->bindValue(':page', $page, PDO::PARAM_STR);
        $statement->bindValue(':email', $email, PDO::PARAM_STR);
        $statement->bindValue(':author', $author, PDO::PARAM_STR);
        $statement->bindValue(':review', $review, PDO::PARAM_STR);
        $statement->bindValue(':lang', $lang, PDO::PARAM_STR);
        $statement->bindValue(':token', $activation_token, PDO::PARAM_STR);

        $statement->execute();

        if($activation_token) {
            $this->sendActivationEmail($email, $token, $author);
        }
    }

    /**
     * Handle the email to activate the user rating.
     *
     * @param string $email
     * @param string $token
     * @param string $name
     *
     * @return bool True if the action was performed.
     * @throws \RuntimeException
     */
    protected function sendActivationEmail(string $email, string $token, string $name)
    {
        if (empty($email) || empty($token) || empty($name)) {
            throw new \RuntimeException($this->language->translate('PLUGIN_RATINGS.ACTIVATION_EMAIL_PARAM_FAILURE'));
        }

        // Make sure to use the system wide and not the plugin configuration
        $system_config = $this->grav['config'];
        $param_sep = $system_config->get('system.param_sep', ':');
        $activation_link = $this->grav['base_url_absolute'] . $this->config->get('route_activate') . '/token' . $param_sep . $token;

        $site_name = $system_config->get('site.title', 'Website');
        $site_link = $this->grav['base_url_absolute'];
        $author = $system_config->get('site.author.name', '');
        $fullname = $name;

        $subject = $this->language->translate(['PLUGIN_RATINGS.ACTIVATION_EMAIL_SUBJECT', $site_name]);
        $content = $this->language->translate(['PLUGIN_RATINGS.ACTIVATION_EMAIL_BODY',
            $fullname,
            $activation_link,
            $site_name,
            $author,
            $site_link
        ]);
        $to = $email;
        $sent = EmailUtils::sendEmail($subject, $content, $to);

        if ($sent < 1) {
            throw new \RuntimeException($this->language->translate('PLUGIN_RATINGS.EMAIL_SENDING_FAILURE'));
        }

        return true;
    }

    public function getRatings(string $page, int $rating = NULL) {
        $query = "SELECT rating, page, email, author, review, date, moderated FROM {$this->table_ratings} WHERE page = :page";

        if (null !== $rating) {
            $query .= ' AND rating = :rating';
        }

        $statement = $this->db->prepare($query);
        if (null !== $rating) {
            $statement->bindValue(':rating', $rating, PDO::PARAM_INT);
        }
        $statement->bindValue(':page', $page, PDO::PARAM_STR);
        $statement->execute();

        // We want only the associated values e.g.: 'rating' -> 5
        // instead of also having array indexes: 0 -> 5
        $results = $statement->fetchAll(PDO::FETCH_ASSOC);

        // If there a no results, an empty arra [] will be returned.
        return $results;
    }

    public function hasAlreadyRated($page, $email) {
        $query = "SELECT EXISTS(SELECT 1 FROM {$this->table_ratings} WHERE page = :page AND email = :email LIMIT 1)";
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

      $query = "SELECT COUNT(DISTINCT page) FROM {$this->table_ratings} WHERE email = :email";
      $statement = $this->db->prepare($query);
      $statement->bindValue(':email', $email, PDO::PARAM_STR);
      $statement->execute();
      $result = $statement->fetchColumn();

      // NOTE: we are doing a lazy check here (>= instead of >==),
      // as the database will return a string instead of an int.
      return $result >= $limit ? true : false;
    }

    public function createTables()
    {
        $commands = [
            // NOTE: Autoincrement is somehow special in sqlite:
            // https://stackoverflow.com/questions/7905859/is-there-an-auto-increment-in-sqlite
            "CREATE TABLE IF NOT EXISTS {$this->table_ratings} (
              id INTEGER,
              page VARCHAR(255),
              rating INTEGER DEFAULT 0,
              email VARCHAR(255),
              author VARCHAR(255),
              review TEXT,
              date TEXT,
              lang VARCHAR(255),
              token VARCHAR(255),
              moderated BOOL DEFAULT TRUE,
              PRIMARY KEY (id))",
        ];

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

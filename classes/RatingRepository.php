<?php

declare(strict_types=1);

namespace Grav\Plugin\Ratings;

use Grav\Plugin\Database\PDO;

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

    public function create(Rating $rating) : Rating {
        // If the ID is set, flag this as error
        if (isset($rating->id)) {
            throw new \LogicException(
                'Rating already with this id exists.'
            );
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

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
    protected string $table_ratings = 'ratings';

    // Table version used to track table migrations
    protected int $user_version = 1;

    public function __construct($database, $connect_string)
    {
        $this->db = $database->connect($connect_string);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (!$this->db->tableExists($this->table_ratings)) {
            $this->createTables();
        }
        else {
            $this->migrateTables();
        }
    }

    public function create(Rating $rating) : Rating {
        // If the ID is set, flag this as error
        if (isset($rating->id)) {
            throw new \LogicException(
                'Rating already with this id exists.'
            );
        }

        $query = "INSERT INTO {$this->table_ratings}
          (page, email, author, date, stars, title, review, activated, moderated, verified, reported, token, expire, lang, verification_code)
          VALUES
          (:page, :email, :author, :date, :stars, :title, :review, :activated, :moderated, :verified, :reported, :token, :expire, :lang, :verification_code)";

        $statement = $this->db->prepare($query);
        $statement->bindValue(':page', $rating->page, PDO::PARAM_STR);
        $statement->bindValue(':email', $rating->email, PDO::PARAM_STR);
        $statement->bindValue(':author', $rating->author, PDO::PARAM_STR);
        $statement->bindValue(':date', $rating->date, PDO::PARAM_INT);
        $statement->bindValue(':stars', $rating->stars, PDO::PARAM_INT);
        $statement->bindValue(':title', $rating->title, PDO::PARAM_STR);
        $statement->bindValue(':review', $rating->review, PDO::PARAM_STR);
        $statement->bindValue(':activated', $rating->activated, PDO::PARAM_BOOL);
        $statement->bindValue(':moderated', $rating->moderated, PDO::PARAM_BOOL);
        $statement->bindValue(':verified', $rating->verified, PDO::PARAM_BOOL);
        $statement->bindValue(':reported', $rating->reported, PDO::PARAM_BOOL);
        $statement->bindValue(':token', $rating->token, PDO::PARAM_STR);
        $statement->bindValue(':expire', $rating->expire, PDO::PARAM_INT);
        $statement->bindValue(':lang', $rating->lang, PDO::PARAM_STR);
        $statement->bindValue(':verification_code', $rating->verification_code, PDO::PARAM_STR);
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

    public function read(int $id) : array {
        $query = "SELECT id, page, stars, email, author, date, title, review,
          lang, token, expire, activated, moderated, reported
          FROM {$this->table_ratings}
          WHERE id = :id";

        $statement = $this->db->prepare($query);
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

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

        $query = "UPDATE {$this->table_ratings}
          SET page = :page,
              email = :email,
              author = :author,
              date = :date,
              stars = :stars,
              title = :title,
              review = :review,
              activated = :activated,
              moderated = :moderated,
              verified = :verified,
              reported = :reported,
              token = :token,
              expire = :expire,
              lang = :lang,
              verification_code = :verification_code
          WHERE id = :id";
        $statement = $this->db->prepare($query);
        $statement->bindValue(':id', $rating->id, PDO::PARAM_STR);
        $statement->bindValue(':page', $rating->page, PDO::PARAM_STR);
        $statement->bindValue(':email', $rating->email, PDO::PARAM_STR);
        $statement->bindValue(':author', $rating->author, PDO::PARAM_STR);
        $statement->bindValue(':date', $rating->date, PDO::PARAM_INT);
        $statement->bindValue(':stars', $rating->stars, PDO::PARAM_INT);
        $statement->bindValue(':title', $rating->title, PDO::PARAM_STR);
        $statement->bindValue(':review', $rating->review, PDO::PARAM_STR);
        $statement->bindValue(':activated', $rating->activated, PDO::PARAM_BOOL);
        $statement->bindValue(':moderated', $rating->moderated, PDO::PARAM_BOOL);
        $statement->bindValue(':verified', $rating->verified, PDO::PARAM_BOOL);
        $statement->bindValue(':reported', $rating->reported, PDO::PARAM_BOOL);
        $statement->bindValue(':token', $rating->token, PDO::PARAM_STR);
        $statement->bindValue(':expire', $rating->expire, PDO::PARAM_INT);
        $statement->bindValue(':lang', $rating->lang, PDO::PARAM_STR);
        $statement->bindValue(':verification_code', $rating->verification_code, PDO::PARAM_STR);

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

    public function find(?string $page = null, ?string $email = null,
      ?int $stars = null, ?string $token = null, ?string $verification_code = null) {
        // NOTE: "WHERE 1" is used as dummy to use the AND construct below.
        // Normally "WHERE TRUE" would be nicer, introduced by SQLite 3.23.
        // However some servers are still running debian stretch with SQLite 3.16.
        $query = "SELECT *
          FROM {$this->table_ratings}
          WHERE 1";

        if (null !== $page) {
            $query .= ' AND page = :page';
        }
        if (null !== $email) {
            $query .= ' AND email = :email';
        }
        if (null !== $stars) {
            $query .= ' AND stars = :stars';
        }
        if (null !== $token) {
            $query .= ' AND token = :token';
        }
        if (null !== $verification_code) {
            $query .= ' AND verification_code = :verification_code';
        }

        $statement = $this->db->prepare($query);
        if (null !== $page) {
            $statement->bindValue(':page', $page, PDO::PARAM_STR);
        }
        if (null !== $email) {
            $statement->bindValue(':email', $email, PDO::PARAM_STR);
        }
        if (null !== $stars) {
            $statement->bindValue(':stars', $stars, PDO::PARAM_INT);
        }
        if (null !== $token) {
            $statement->bindValue(':token', $token, PDO::PARAM_STR);
        }
        if (null !== $verification_code) {
            $statement->bindValue(':verification_code', $verification_code, PDO::PARAM_STR);
        }
        $statement->execute();

        $results = $statement->fetchAll(PDO::FETCH_CLASS, Rating::class);
        return $results;
    }

    public function createTables(): void
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
              date INTEGER NOT NULL,
              title VARCHAR(255),
              review TEXT,
              lang VARCHAR(255) DEFAULT NULL,
              token VARCHAR(255) DEFAULT NULL,
              expire INTEGER DEFAULT NULL,
              activated BOOL DEFAULT TRUE NOT NULL,
              moderated BOOL DEFAULT TRUE NOT NULL,
              verified BOOL DEFAULT FALSE NOT NULL,
              reported BOOL DEFAULT FALSE NOT NULL,
              verification_code VARCHAR(255) DEFAULT NULL)"
        ];
        // TODO add SQL CONSTRAINT to limit starss to 1-5? -> use config value

        // execute the sql commands to create new tables
        foreach ($commands as $command) {
            $this->db->exec($command);
        }
    }

    public function migrateTables(): void
    {
        $query = "PRAGMA user_version";
        $statement = $this->db->prepare($query);
        $statement->execute();
        $db_user_version = (int) $statement->fetchColumn();

        // Database is up to date
        if ($db_user_version === $this->user_version)
        {
            return;
        }

        // Check if plugin is outdated and the database on disk is already newer
        if ($db_user_version > $this->user_version)
        {
            throw new \RuntimeException(
                'Existing database is newer than supported. Current version: ' . $db_user_version
            );
        }

        // Migrate database code
        if ($db_user_version < 1)
        {
            // Add verification columns
            $commands = [
                "ALTER TABLE {$this->table_ratings}
                  ADD COLUMN verified BOOL DEFAULT FALSE NOT NULL",
                "ALTER TABLE {$this->table_ratings}
                  ADD COLUMN verification_code VARCHAR(255) DEFAULT NULL"
            ];

            foreach ($commands as $command) {
                $this->db->exec($command);
            }
        }

        // Set version to latest
        $command = "PRAGMA user_version = {$this->user_version}";
        $this->db->exec($command);
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

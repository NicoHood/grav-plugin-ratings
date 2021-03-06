<?php

declare(strict_types=1);

namespace Grav\Plugin\Ratings;

class Rating
{
    public ?int $id = null;
    public string $page;
    public string $email;
    public string $author;
    public int $date;
    public int $stars;
    public ?string $title;
    public ?string $review;
    public ?string $lang = null;
    public ?string $token = null;

    // Absolut time when the token expires
    // NULL Means it never expires
    public ?int $expire = NULL;
    public bool $activated = true;
    public bool $moderated = true;
    public bool $reported = false;

    function token_expired() : bool {
        if ($this->expire === NULL) {
            return false;
        }
        else {
            return time() > $this->expire;
        }
    }

    function set_expire_time(int $expire_time) {
        $this->token = md5(uniqid((string)mt_rand(), true));

        // Do not calculate an expire date if unlimited expire time was choosen
        $this->expire = $this->date + $expire_time;
        $this->activated = false;
    }

    function set_expired() {
        // 0 is the very first possible date and marks an expired token
        $this->expire = 0;
    }

    function token_activated() : bool {
        return $this->activated === true;
    }

    function set_token_activated() {
        $this->activated = true;
    }
}

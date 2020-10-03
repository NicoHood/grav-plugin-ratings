<?php

declare(strict_types=1);

namespace Grav\Plugin\Ratings;

class Rating
{
    public ?int $id = null;
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

    function token_expired() : bool {
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

    function token_activated() :bool {
        return $this->expire === NULL;
    }

    function set_token_activated() {
        $this->expire = NULL;
    }
}

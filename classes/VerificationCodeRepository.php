<?php

declare(strict_types=1);

namespace Grav\Plugin\Ratings;

use RocketTheme\Toolbox\File\File;
use \Grav\Framework\File\Formatter\CsvFormatter;
use \Grav\Framework\File\CsvFile;

class VerificationCodeRepository
{
    private $csv_data;

    public function __construct($filepath, $delimiter = ',')
    {
        $csv_file = new CsvFile($filepath, new CsvFormatter(['delimiter' => $delimiter]));
        $this->csv_data = $csv_file->load();
    }

    // Return NULL if code was not found
    public function getVerificationCode(string $verification_code) {
        // Search verification code
        foreach ($this->csv_data as $entry) {
            if ($verification_code === $entry['code']) {
                return [
                    'code' => $entry['code'],
                    'page' => $entry['page']
                ];
            }
        }
        return NULL;
    }
}

<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitc14a40180ff0f2d6d230ba326e19efe1
{
    public static $prefixLengthsPsr4 = array (
        'G' => 
        array (
            'Grav\\Plugin\\Ratings\\' => 20,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Grav\\Plugin\\Ratings\\' => 
        array (
            0 => __DIR__ . '/../..' . '/classes',
        ),
    );

    public static $classMap = array (
        'Grav\\Plugin\\RatingsPlugin' => __DIR__ . '/../..' . '/ratings.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitc14a40180ff0f2d6d230ba326e19efe1::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitc14a40180ff0f2d6d230ba326e19efe1::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitc14a40180ff0f2d6d230ba326e19efe1::$classMap;

        }, null, ClassLoader::class);
    }
}
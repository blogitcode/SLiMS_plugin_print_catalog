<?php
/**
 * Plugin Name: Print Item Catalog
 * Plugin URI: https://www.blogitcode.com/search/label/SLiMS
 * Description: Print Item Catalog
 * Version: 0.0.1
 * Author: BlogITCode
 * Author URI: https://www.blogitcode.com/
 */

// get plugin instance
$plugin = \SLiMS\Plugins::getInstance();

// registering menus
$plugin->registerMenu('bibliography', __('Print Catalogue'), __DIR__ . '/index.php');

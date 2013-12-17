#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2013 Nima Dehnashi
 * https://github.com/ndehnashi/update-license-headers
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * @file
 * This script helps in updating the license headers for SugarCRM 7.0.
 */

if (version_compare(PHP_VERSION, '5.3.', '<')) {
    throw new \RuntimeException("This script requires php 5.3.0 version or above.");
}

// Pretend we are a valid sugar entry point
if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}

process($argv);

function process($argv) {
    // 'd' = dir, 'e' = file extensions
    $shortOpts = "d::e::";
    $longOpts = array(
        "dir::",
        "ext::"
    );

    $opts = getopt($shortOpts, $longOpts);

    // This option specifies what directory of files to target.
    // e.g. -f=clients/base/views will only update license headers in the views folder.
    $dir = getcwd();
    if (!empty($opts['d'])) {
        $dir = $opts['d'];
    } else if (!empty($opts['dir'])) {
        $dir = $opts['dir'];
    }

    // This option specifies what type of files (extensions) to target.
    // e.g. -e=hbs will only update license headers on hbs files.
    $type = 'all';
    if (!empty($opts['e'])) {
        $type = $opts['e'];
    } else if (!empty($opts['ext'])) {
        $type = $opts['ext'];
    }

    $updater = new LicenseUpdater();
    $rdi = new RecursiveDirectoryIterator($dir);
    $filter = new CustomRecursiveFilterIterator($rdi);
    $files = new RecursiveIteratorIterator($filter);

    foreach($files as $file) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if ($type === 'all' || $ext === $type) {
            $updater->setFileExt($ext);
            $updater->run($file, $ext);
        }
    }
}

class LicenseUpdater
{
    protected $fileExt = '';

    protected $fileContent = '';

    // We can't insert license headers in these types of files.
    protected $blacklist = array('json');

    protected $headerText = <<<'EOT'
/*
 * By installing or using this file, you are confirming on behalf of the entity
 * subscribed to the SugarCRM Inc. product ("Company") that Company is bound by
 * the SugarCRM Inc. Master Subscription Agreement ("MSA"), which is viewable at:
 * http://www.sugarcrm.com/master-subscription-agreement
 *
 * If Company is not bound by the MSA, then by installing or using this file
 * you are agreeing unconditionally that Company will be bound by the MSA and
 * certifying that you have authority to bind Company accordingly.
 *
 * Copyright (C) 2004-2013 SugarCRM Inc. All rights reserved.
 */
EOT;

    protected $commentBlockMap = array(
        'hbs' => array(
            'start_tok' => '{{!',
            'end_tok' => '}}',
        ),
        'html' => array(
            'start_tok' => '<!--',
            'end_tok' => '-->',
        ),
        'tpl' => array(
            'start_tok' => '{*',
            'end_tok' => '*}',
        ),
        'default' => array(
            'start_tok' => '/*',
            'end_tok' => '*/',
        ),
    );

    // These files require tokens preceding the license header itself.
    protected $prependTokenMap = array(
        'php' => '<?php'
    );

    public function setFileExt($ext)
    {
        $this->fileExt = $ext;
    }

    public function run($file, $ext)
    {
        $this->fileContent = file_get_contents($file);
        $len = strlen($this->fileContent);
        $stack = array();
        $final = array();

        if (!array_key_exists($ext, $this->commentBlockMap)) {
            $ext = 'default';
        }

        // Generate an appropriate header for the file.
        $header = $this->headerText;
        if ($ext !== 'default') {
            if ($ext === 'hbs') {
                // Ultimately, we want to have the "{{!--" token used in hbs
                // license headers regardless of whether "{{!--" or "{{!" was used.
                // This slight hack is OK because "{{!" is a subset of "{{!--".
                $startTok = "{{!--";
                $endTok = "--}}";
                $header = $startTok . "\n" . $this->headerText . "\n" . $endTok;
            } else {
                $header = $this->commentBlockMap[$ext]['start_tok'] . "\n" . $this->headerText . "\n" .
                    $this->commentBlockMap[$ext]['end_tok'];
            }
        }

        $startTokLen = strlen($this->commentBlockMap[$ext]['start_tok']);
        $endTokLen = strlen($this->commentBlockMap[$ext]['end_tok']);

        for($pos = 0; $pos < $len; $pos++) {
            $isStartTok = substr($this->fileContent, $pos, $startTokLen);
            $isEndTok = substr($this->fileContent, $pos, $endTokLen);

            if ($isStartTok === $this->commentBlockMap[$ext]['start_tok']) {
                array_push($stack, $pos);
            } else if ($isEndTok === $this->commentBlockMap[$ext]['end_tok']) {
                if (count($stack) === 1) {
                    $final = array(
                        'start' => reset($stack),
                        'end' => $pos
                    );
                    break;
                } else {
                    array_pop($stack);
                }
            }
        }

        if (!empty($final)) {
            $commentLength = $final['end'] - $final['start'] + $endTokLen;
            $comment = substr($this->fileContent, $final['start'], $commentLength);
            if (strcmp($header, $comment) !== 0) {
                // Check if this is in fact a license header comment.
                if (stripos($comment, 'all rights reserved') !== false) {
                    $this->fileContent = str_replace($comment, $header, $this->fileContent);
                    file_put_contents($file, $this->fileContent, LOCK_EX);
                    echo "{$file} ✓\n";
                } else {
                    echo "Comment found. No license headers found in {$file}, inserting new license header\n";
                    $this->insertHeader($file, $header);
                }
            } else {
                echo "License header up to date in {$file}\n";
            }
        } else {
            echo "No license headers found in {$file}, inserting new license header\n";
            $this->insertHeader($file, $header);
        }
    }

    protected function insertHeader($file, $licenseHeader)
    {
        if (!in_array($this->fileExt, $this->blacklist)) {
            if (array_key_exists($this->fileExt, $this->prependTokenMap)) {
                // We need to prepend the token to the license header first.
                $licenseHeader = $this->prependTokenMap[$this->fileExt] . "\n" . $licenseHeader;
                $this->fileContent = str_replace($this->prependTokenMap[$this->fileExt], $licenseHeader, $this->fileContent);
            } else {
                $this->fileContent = $licenseHeader . "\n" . $this->fileContent;
            }
            file_put_contents($file, $this->fileContent, LOCK_EX);
        }
    }
}

class CustomRecursiveFilterIterator extends RecursiveFilterIterator
{
    public $rejected = array();

    public function accept()
    {
        $filename = $this->current()->getFilename();
        $isValid = !fnmatch('.*', $filename);

        if ($isValid === false) {
            $this->rejected[] = $filename;
        }
        return $isValid;
    }
}

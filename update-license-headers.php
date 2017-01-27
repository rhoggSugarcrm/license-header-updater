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
        if (($type === 'all' || $ext === $type) && is_writable($file)) {
            $updater->fileExt = $ext;
            $updater->fileContent = file_get_contents($file);
            $updater->run($file, $ext);
        }
    }
}

class LicenseUpdater
{
    /**
     * The extension of the current file.
     * @var string
     */
    public $fileExt = '';

    /**
     * The contents of the current file.
     * @var string
     */
    public $fileContent = '';

    // TODO: Change the logic to a whitelist instead, and consider making it public!
    /**
     * We can't insert license headers in these types of files.
     * @var array
     */
    protected $blacklist = array('json');

    /**
     * This is the default license header itself.
     * @var nowdoc
     */
    protected $headerText = <<<'EOT'
/*
 * Your installation or use of this SugarCRM file is subject to the applicable
 * terms available at
 * http://support.sugarcrm.com/Resources/Master_Subscription_Agreements/.
 * If you do not agree to all of the applicable terms or do not have the
 * authority to bind the entity as an authorized representative, then do not
 * install or use this SugarCRM file.
 *
 * Copyright (C) SugarCRM Inc. All rights reserved.
 */
EOT;

    /**
     * This is a map that defines comment block start and end tokens
     * for certain file types. Tokens that are to be used in the license
     * header should be stored in this variable.
     * @var array
     */
    protected $defaultTokenPairs = array(
        'hbs' => array(
            'start_tok' => '{{!--',
            'end_tok' => '--}}'
        ),
        'html' => array(
            'start_tok' => '<!--',
            'end_tok' => '-->',
        ),
        'tpl' => array(
            'start_tok' => '{*',
            'end_tok' => '*}',
        ),
        'standard' => array(
            'start_tok' => '/*',
            'end_tok' => '*/',
        ),
    );

    /**
     * This is a map that defines comment block start and end tokens
     * for certain file types. Some file types may have varying tokens
     * (e.g. .hbs, .tpl files), so these are stored in this variable.
     * @var array
     */
    protected $alternateTokenPairs = array(
        'hbs' => array(
            array(
                'start_tok' => '{{!',
                'end_tok' => '}}',
            ),
        ),
        'tpl' => array(
            array(
                'start_tok' => '<!--',
                'end_tok' => '-->',
            ),
        ),
    );

    /**
     * These files require tokens preceding the license header itself.
     * @var array
     */
    protected $prependTokenMap = array(
        'php' => '<?php'
    );

    /**
     * Main script-execution method. Generates the appropriate header
     * per filetype, and parses the files to replace/insert the header
     * accordingly.
     * @param  string $file The file's contents.
     * @param  string $ext  The file's extension.
     */
    public function run($file, $ext)
    {
        if (!array_key_exists($ext, $this->defaultTokenPairs)) {
            $ext = 'standard';
        }

        $tokens = $this->defaultTokenPairs[$ext];

        // Generate an appropriate header for the file.
        $header = $this->headerText;
        if ($ext !== 'standard') {
            $header = $tokens['start_tok'] . "\n" . $this->headerText . "\n" .
                $tokens['end_tok'];
        }

        // Try to obtain the header with the default tokens.
        $headerPos = $this->getHeaderPosition($tokens);

        // If empty, try obtaining the header with alternate tokens.
        if (empty($headerPos) && array_key_exists($this->fileExt, $this->alternateTokenPairs)) {
            foreach($this->alternateTokenPairs[$this->fileExt] as $pair) {
                $headerPos = $this->getHeaderPosition($pair);
                if (!empty($headerPos)) {
                    $tokens = $pair;
                    break;
                }
            }
        }

        if (!empty($headerPos)) {
            // Obtain the substring license header in the current file, to analyze/replace it.
            $endTokLen = strlen($tokens['end_tok']);
            $commentLength = $headerPos['end'] - $headerPos['start'] + $endTokLen;
            $comment = substr($this->fileContent, $headerPos['start'], $commentLength);

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

    /**
     * This function parses the file content and returns the start
     * and end positions of the header's comment block tokens. It
     * pushes to a stack whenever a start token is found and pops
     * the stack element whenever an end token is found, returning
     * the positions when the stack is empty.
     * @param  array $tokenPair An array of start/end tokens.
     * @return array $result
     */
    protected function getHeaderPosition($tokenPair)
    {
        $result = array();
        $stack = array();
        $len = strlen($this->fileContent);
        $startTokLen = strlen($tokenPair['start_tok']);
        $endTokLen = strlen($tokenPair['end_tok']);

        for($pos = 0; $pos < $len; $pos++) {
            $isStartTok = substr($this->fileContent, $pos, $startTokLen);
            $isEndTok = substr($this->fileContent, $pos, $endTokLen);

            if ($isStartTok === $tokenPair['start_tok']) {
                array_push($stack, $pos);
            } else if ($isEndTok === $tokenPair['end_tok']) {
                if (count($stack) === 1) {
                    $result = array(
                        'start' => reset($stack),
                        'end' => $pos
                    );
                    return $result;
                } else {
                    array_pop($stack);
                }
            }
        }
    }

    /**
     * Inserts the $licenseHeader into the $file provided, at the beginning
     * of the file. If certain files (such as php), require certain tokens to
     * be prepended to the file before the license header itself, this function
     * accounts for that case.
     * @param  string $file The file's contents.
     * @param  string $licenseHeader The desired license header.
     */
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

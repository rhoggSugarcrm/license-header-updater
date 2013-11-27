# License Header Updater

This is a very early version of the Sugar 7.0 License Header updater.

## How to use the script

By default, the script will recursively scan and update license headers in its current directory:

```bash
$ ./update-license-headers
```

You can run this with directories:

```bash
$ ./update-license-headers -d="directory/to/scan"
$ ./update-license-headers --dir="directory/to/scan"
```

You can narrow the type of files you want to scan (example below will only update .js files):

```bash
$ ./update-license-headers -e="js"
$ ./update-license-headers --ext="js"
```

## TODO

-Refactor code
-Allow multiple file extensions
-Add --help option
-Allow --verbose logging option
-Allow --dry-run option

[SugarCRM]: http://www.sugarcrm.com/

# Merging git projects with history

This script merges one or more git repositories into the existing central
repository preserving all history. A new branch is created in the main project for
merge results. For each subproject the script will merge the main branch as well
as any remote branch that is not merged into the subproject's main branch (this
is your work in progress). Subprojects will be copied into the subdirectory of
the main project. Commiter, author, gpg, and date information is preserved.

If you have a lot of subprojects to merge, you can get an
[interesting view](https://twitter.com/dmitryd/status/852870074662883328) if
you look at the git tree in the program like Atlassian SourceTree. This is
really a lot of history!

## Requirements

There are tow versions of the script: PHP and python. PHP wa sthe first one
because I know this language better. Python version was made from the PHP
version.

### Requirements for the PHP version

* PHP 5.6+
* git 2.0+
* Linux/Unix/OS X (not Windows!)
* bash or zsh as the main shell (/bin/sh will not work!)
* zsh

### Requirements for the Python version

* Puthon 3.5 or newer
* git 2.0+
* Linux/Unix/OS X (not Windows!)
* bash or zsh as the main shell (/bin/sh will not work!)
* zsh

## Running the script

To run the script, you do this:

```sh
merge-git-projects.php configfile
```

or

```sh
pyhton merge-git-projects.py configfile
```

PHP version will always produce verbose output while python version will do it only with `-v` option.

## Configuration file

Configuration file describes what you want to merge and where. A sample file is
included with this project.

Config file is a JSON file that consists from three sections:

### gitConfig

This section defines configuration for the repository. For example, you may need
to disable gpg signing for this script because it does not work in the subshell.

Each entry name is a configuration key (such as `user.email`) and value is the
configuration value.

### mainProject

This section describes your main project. All entries are mandatory.

* `name` is the subdirectory name where the project will be cloned inside the current directory.
* `repository` is the url of the remote repository. Any git remote is ok.
* `mainBranch` is the source branch. The script will create a new branch from its HEAD to do merges.
* `createBranch` is the branch where main branches of subprojects will be merged.

### projectsToMerge

Subkeys are subdirectory names that will be created temporarily in the current directory for each subprojects.

Values are objects with the following mandatory keys:

* `repository` is the url of the remote repository. Any git remote is ok.
* `path` is the path relative to the root path of the main repository where
this subproject should be imported.
* `mainBranch` is the main branch. The script will merge this branch to the
`createBranch` branch of the main project and test other remote branches for
being merged to this branch. Any remote branch that is not merged to this
branch will be copied to the main repository too.
* `ignoreBranches` is a PCRE regular expression that allows to skip some branches.
Normally should be set to `origin/(HEAD|master|development)` (assuming you use
GitFlow). It should list any non-feature branches that you use plus HEAD.  

## License

GPL v3. If you do derived work, please, add me to credits.

## Support

I cannot provide free support for free projects. But you are welcome to open
bug reports and make pull requests. I can be reached at `dmitry.dulepov@gmail.com`.
#!/usr/bin/env php
<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2017 Dmitry Dulepov <dmitry.dulepov@gmail.com>
*  All rights reserved
*
*  This script is free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 3 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

/**
 * This class contains a tool to merge several git repositories to
 * a single repository preserving history.
 *
 * Credits:
 * - http://stackoverflow.com/a/14470212/2071628
 * - http://stackoverflow.com/a/39027521/2071628
 * - http://stackoverflow.com/a/4991675/2071628
 *
 * Requirements:
 * - PHP 5.6+
 * - git 2.0+
 * - Linux/Unix/OS X (not Windows!)
 * - bash or zsh as the main shell
 *
 * @author Dmitry Dulepov <dmitry.dulepov@gmail.com>
 */
class Merge {

    /** Set this to branches to be ignored in merged projects */
    const IGNORE_BRANCHES = '/origin\/(HEAD|master|dev)/';

    /**
     * Your desired git config for the merged project is here.
     *
     * @var array
     */
    protected $gitConfig = array();

    /**
     * Configuration of the main project.
     *
     * @var array
     */
    protected $mainProject = array();

    /**
     * Confugration for projects to merge.
     *
     * @var array
     */
    protected $projectsToMerge = array();

    /**
     * Tracker for created git branches.
     *
     * @internal
     * @var array
     */
    protected $createdLocalBranches = array();

    /**
     * Creates the instance of the class.
     */
    public function __construct() {
        if ($GLOBALS['argc'] < 2) {
            printf("Format: %s configfile.json\n", basename(__FILE__));
            exit(1);
        }

        $this->parseConfiguration();

        // Prevent merge commit message prompt
        putenv('GIT_MERGE_AUTOEDIT=no');
    }

    /**
     * Executes the merge.
     */
    public function execute() {
        $this->cloneRepository($this->mainProject['repository'], $this->mainProject['name'], $this->mainProject['mainBranch']);
        $this->configureRepository();
        $this->createNewMainBranch();
        foreach ($this->projectsToMerge as $projectName => $projectToMerge) {
            $this->cloneRepository($projectToMerge['repository'], $projectName, $projectToMerge['mainBranch']);
            $this->rewriteHistory($projectName);
            $this->findNonMergedBranches($projectName);
            $this->mergeProject($projectName);
        }
    }

    /**
     * Clones the repository.
     *
     * @param string $repository
     * @param string $subDirectory
     * @param string $mainBranch
     */
    protected function cloneRepository($repository, $subDirectory, $mainBranch) {
        if (file_exists(getcwd() . '/' . $subDirectory)) {
            $this->executeShellCommand('rm -fR %s', array(getcwd() . '/' . $subDirectory));
            echo "Removed directory: $subDirectory\n";
        }
        $this->executeShellCommand('git clone %s %s -b %s', array($repository, $subDirectory, $mainBranch));
    }

    /**
     * Configures the repository.
     */
    protected function configureRepository() {
        $currentDirectory = getcwd();
        chdir($currentDirectory . '/' . $this->mainProject['name']);
        foreach ($this->gitConfig as $key => $value) {
            $this->executeShellCommand('git config %s %s', array(escapeshellarg($key), escapeshellarg($value)));
        }
        chdir($currentDirectory);
    }

    /**
     * Creates a new main branch.
     */
    protected function createNewMainBranch() {
        $currentDirectory = getcwd();
        chdir($currentDirectory . '/' . $this->mainProject['name']);
        $this->executeShellCommand('git checkout -b %s', array($this->mainProject['createBranch']));
        chdir($currentDirectory);
    }

    /**
     * Runs emercency shell and prompts the user whether to continue or not.
     */
    protected function emergencyShell() {
        echo "Something went wrong. Bringing the emergency shell to correct errors manually...\n";
        echo "===========================================\n\n";

        $shell = getenv('SHELL');
        exec($shell . ' -l');

        echo "\n\n===========================================\n\n";
        echo "Emergency shell finished. ";
        do {
            echo "Continue (y/n)? ";
            $answer = strtolower(trim(fgets(STDIN)));
        } while ($answer !== 'y' && $answer !== 'n');

        if ($answer === 'n') {
            exit(1);
        }
    }

    /**
     * Executes shell command and returns the last line.
     *
     * @param string $format
     * @param array $parameters
     * @param array $output
     * @return string
     */
    protected function executeShellCommand($format, array $parameters = array(), array &$output = null) {
        $exitCode = 0;
        $output = array();

        $command = vsprintf($format, $parameters);

        echo "Executing: $command\n";

        $command .=  ' 2>&1';
        exec(getenv('SHELL') . ' -c ' . escapeshellarg($command), $output, $exitCode);
        $lastLine = end($output);
        if ($exitCode != 0) {
            foreach ($output as $line) {
                print("$line\n");
            }
            $this->emergencyShell();
        }

        return trim($lastLine);
    }

    /**
     * Finds non-merged branches in the projects, checks them out and saves for later use.
     *
     * WARNING: this must be called after history rewrite!
     *
     * @param string $projectName
     */
    protected function findNonMergedBranches($projectName) {
        $output = array();
        $project = &$this->projectsToMerge[$projectName];
        $currentDirectory = getcwd();
        chdir($currentDirectory . '/' . $projectName);
        $this->executeShellCommand('git branch -r --no-merged %s', array($project['mainBranch']), $output);

        $branchStartingPointCommand = "diff -u <(git rev-list --first-parent %s) <(git rev-list --first-parent %s) | sed -ne 's/^ //p' | head -1";
        foreach ($output as $remoteBranch) {
            $remoteBranch = trim($remoteBranch);
            $regexp = '/' . str_replace('/', '\/', $project['ignoreBranches']) . '/';
            if (($project['ignoreBranches'] === '' || !preg_match($regexp, $remoteBranch)) && substr($remoteBranch, 0, 7) === 'origin/') {
                $localBranch = substr($remoteBranch, 7);
                $this->executeShellCommand('git checkout -b %s %s', array($localBranch, $remoteBranch));
                $project['copyBranches'][$localBranch] = $this->executeShellCommand($branchStartingPointCommand, array($localBranch, $project['mainBranch']));
            }
        }
        $this->executeShellCommand('git checkout %s', array($project['mainBranch']));
        chdir($currentDirectory);
    }

    /**
     * Merges the specified project into the new project.
     *
     * @param string $projectName
     */
    protected function mergeProject($projectName) {
        $project = $this->projectsToMerge[$projectName];

        $currentDirectory = getcwd();
        chdir($currentDirectory . '/' . $this->mainProject['name']);

        $this->executeShellCommand('git remote add -f %1$s ../%1$s', array($projectName));
        $this->executeShellCommand('git merge --no-ff %s/%s --allow-unrelated-histories', array($projectName, $project['mainBranch']));
        foreach ($project['copyBranches'] as $branchName => $commitId) {
            if (!isset($this->createdLocalBranches[$branchName])) {
                $this->executeShellCommand('git checkout -b %s %s', array($branchName, $commitId));
                $this->createdLocalBranches[$branchName] = 1;
            }
            else {
                echo "Warning: merging into existing local branch ($branchName)!\n";
                $this->executeShellCommand('git checkout %s', array($branchName));
            }
            $this->executeShellCommand('git merge --no-ff %s/%s --allow-unrelated-histories', array($projectName, $branchName));
        }
        // Reset to the newly created branch
        $this->executeShellCommand('git checkout %s', array($this->mainProject['createBranch']));

        $this->executeShellCommand('git remote remove %s', array($projectName));

        chdir($currentDirectory);

        $this->executeShellCommand('rm -fR %s', array($projectName));
    }

    /**
     * Parses and vaidates the configuration.
     */
    protected function parseConfiguration() {
        $configrationFile = $GLOBALS['argv'][1];
        $jsonString = @file_get_contents($configrationFile);
        if (!$jsonString) {
            printf("File %s cannot be read\n", $configrationFile);
            exit(1);
        }
        $configuration = json_decode($jsonString, true);
        if (!is_array($configuration)) {
            printf("File %s does not contain valid json\n", $configrationFile);
            exit(1);
        }

        $this->mainProject = (array)$configuration['mainProject'];
        $this->projectsToMerge = (array)$configuration['projectsToMerge'];
        $this->gitConfig = (array)$configuration['gitConfig'];

        // Validate
        foreach (array('name', 'repository', 'mainBranch', 'createBranch') as $key) {
            if (!isset($this->mainProject[$key])) {
                printf("Mandatory key '$key' is missing in the 'mainProject' configuration\n");
                exit(1);
            }
        }
        foreach ($this->projectsToMerge as $name => &$projectConfiguration) {
            foreach (array('repository', 'path', 'mainBranch', 'ignoreBranches') as $key) {
                if (!isset($projectConfiguration[$key])) {
                    printf("Mandatory key '$key' is missing in the '$name' project configuration\n");
                    exit(1);
                }
            }
            $projectConfiguration['copyBranches'] = array();
        }
    }

    /**
     * Rewrites history to place the files from the subproject to a subdirectory
     * like in the main project.
     *
     * @param $projectName
     */
    protected function rewriteHistory($projectName) {
        $project = $this->projectsToMerge[$projectName];
        $currentDirectory = getcwd();
        chdir($currentDirectory . '/' . $projectName);

        $command = "git filter-branch -f --prune-empty --tree-filter \"mkdir -p %s ; git ls-tree --name-only \\\$GIT_COMMIT | xargs -I file mv file %s\" -- --all";
        $this->executeShellCommand($command, array($project['path'], $project['path']));

        chdir($currentDirectory);
    }
}

if (PHP_SAPI === 'cli') {
    (new Merge())->execute();
}


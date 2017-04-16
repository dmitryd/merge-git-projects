import argparse
import json
import os
import pty
import re
import shlex
import shutil
import subprocess
import sys

"""
This file contains a tool to merge several git repositories to
a single repository preserving history.

Credits:
- http://stackoverflow.com/a/14470212/2071628
- http://stackoverflow.com/a/39027521/2071628
- http://stackoverflow.com/a/4991675/2071628

Requirements:
- Python 3.5+
- git 2.0+
- Linux/Unix/OS X (not Windows!)
- bash or zsh as the main shell
- zsh

Author: Dmitry Dulepov <dmitry.dulepov@gmail.com>
"""
class MergeGitProjects:

    def __init__(self):
        """
        Initializes the class.
        """
        self.__configuration = {}
        self.__created_local_branches = []
        self.__verbose = False
        # Disable merge message editing
        os.putenv('GIT_MERGE_AUTOEDIT', 'no')


    def execute(self):
        """
        Executes the merge
        """
        self._parse_arguments()

        print("Creating a copy of the main repository...")

        self._clone_repository(self.__configuration['mainProject']['repository'],
                self.__configuration['mainProject']['name'], self.__configuration['mainProject']['mainBranch'])
        self._configure_repository()
        self._create_new_main_branch()

        for project_name in self.__configuration['projectsToMerge']:
            print("Merging project '%s'..." % project_name)
            project_to_merge = self.__configuration['projectsToMerge'][project_name]
            self._clone_repository(project_to_merge['repository'], project_name, project_to_merge['mainBranch'])
            self._rewrite_history(project_name)
            self._find_non_merged_branches(project_name)
            self._merge_project(project_name)


    def _clone_repository(self, repository, directory_name, main_branch):
        """
        Clones the remote repository in the subdirectory and checks out the given branch
        
        :param repository: repository specification 
        :param directory_name: subdirectory name (relative to current) where to clone
        :param main_branch: branch to checkout
        """

        def directory_was_not_removed():
            print('Error: could not remove %s' % directory_name)
            sys.exit(1)

        current_directory = os.getcwd()
        repository_directory = os.path.join(current_directory, directory_name)

        if os.path.exists(repository_directory):
            if self.__verbose:
                print('Removing directory %s' % directory_name)
            shutil.rmtree(directory_name, onerror=directory_was_not_removed)

        self._execute_shell_command('git clone %s %s -b %s', repository, directory_name, main_branch)


    def _configure_repository(self):
        """
        Configures the main repository according to the configuration.
        """
        current_directory = os.getcwd()
        os.chdir(os.path.join(current_directory, self.__configuration['mainProject']['name']))

        for key in self.__configuration['gitConfig']:
            self._execute_shell_command('git config %s %s', shlex.quote(key), shlex.quote(self.__configuration['gitConfig'][key]))

        os.chdir(current_directory)


    def _create_new_main_branch(self):
        """
        Creates a new branch in the main repository
        """
        current_directory = os.getcwd()
        os.chdir(os.path.join(current_directory, self.__configuration['mainProject']['name']))
        self._execute_shell_command('git checkout -b %s', self.__configuration['mainProject']['createBranch'])
        os.chdir(current_directory)


    def _emergency_shell(self):
        """
        Runs emergency shell
        """
        print("Something went wrong. Bringing the emergency shell to correct errors manually...\n")
        print("===========================================\n\n")

        pty.spawn([os.getenv('SHELL'), '-l'])

        print("\n\n===========================================\n\n")
        print("Emergency shell finished. ")
        answer = ''
        while answer != 'y' and answer != 'n':
            answer = input("Continue (y/n)? ")

        if answer == 'n':
            sys.exit(1)


    def _execute_shell_command(self, command_format, *args):
        """
        Runs the shell with the given command

        :param command_format: command to run (like 'git checkout %s') 
        :param args: arguments to format the command
        :return: array of lines from stdout
        """
        command = str(command_format) % args

        if self.__verbose:
            print('Executing: %s' % command)

        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, shell=True, executable=os.getenv('SHELL'), universal_newlines=True)
        if result.returncode != 0:
            self._emergency_shell()

        return result.stdout.splitlines()


    def _find_non_merged_branches(self, project_name):
        """
        Finds branches in the project that are not merged to the project's main branch.
        :param: project_name: project name to search 
        """
        new_branches = {}
        project = self.__configuration['projectsToMerge'][project_name]
        branch_starting_point_command = "diff -u <(git rev-list --first-parent %s) <(git rev-list --first-parent dev) | sed -ne 's/^ //p' | head -1"

        current_directory = os.getcwd()
        os.chdir(os.path.join(current_directory, project_name))
        output = self._execute_shell_command('git branch -r --no-merged %s', project['mainBranch'])

        for remote_branch in output:
            remote_branch = remote_branch.strip()
            if project['ignoreBranches'] == '' or not re.match(project['ignoreBranches'], remote_branch) and remote_branch[0:7] == 'origin/':
                local_branch = remote_branch[7:]
                self._execute_shell_command('git checkout -b %s %s', local_branch, remote_branch)
                new_branches[local_branch] = self._execute_shell_command(branch_starting_point_command, local_branch)[0]

        self._execute_shell_command('git checkout %s', project['mainBranch'])

        os.chdir(current_directory)

        self.__configuration['projectsToMerge'][project_name]['copyBranches'] = new_branches


    def _merge_project(self, project_name):
        """
        Merges the project

        :param project_name: project name from the configuration 
        """
        project = self.__configuration['projectsToMerge'][project_name]

        current_directory = os.getcwd()
        os.chdir(os.path.join(current_directory, self.__configuration['mainProject']['name']))

        self._execute_shell_command('git remote add -f %s ../%s', project_name, project_name)
        self._execute_shell_command('git merge --no-ff %s/%s --allow-unrelated-histories', project_name, project['mainBranch'])
        for branch_name in project['copyBranches']:
            commit_id = project['copyBranches'][branch_name]
            if branch_name not in self.__created_local_branches:
                self._execute_shell_command('git checkout -b %s %s', branch_name, commit_id)
                self.__created_local_branches.append(branch_name)
            else:
                print("Warning: merging into existing local branch (%s)!" % branch_name)
                self._execute_shell_command('git checkout %s', branch_name)
            self._execute_shell_command('git merge --no-ff %s/%s --allow-unrelated-histories', project_name, branch_name)

        # Reset to the newly created branch
        self._execute_shell_command('git checkout %s', self.__configuration['mainProject']['createBranch'])

        self._execute_shell_command('git remote remove %s', project_name)

        os.chdir(current_directory)

        shutil.rmtree(os.path.join(current_directory, project_name))


    def _parse_arguments(self):
        """
        Parses arguments
        """
        parser = argparse.ArgumentParser(
            add_help=False,
            description='This script merges one or more git projects into a base projects preserving history.',
            epilog='See https://github.com/dmitryd/merge-git-projects for more information.'
        )
        parser.add_argument('configuration_file_name', metavar='configuration-file-name', help='Configuration file', default='')
        parser.add_argument('-v', action='store_true', dest='verbose', help='Show what is done')
        arguments = parser.parse_args()

        self.__verbose = arguments.verbose

        try:
            configuration_file = open(arguments.configuration_file_name, 'r')
            self.__configuration = json.load(configuration_file)
            configuration_file.close()
        except FileNotFoundError:
            print('Error: configuration file "%s" not found' % arguments.configuration_file_name)
            sys.exit(1)

        for section in ['gitConfig', 'mainProject', 'projectsToMerge']:
            if section not in self.__configuration:
                print('Error: "%s" section is missing in the configuration file' % section)
                sys.exit(1)

        for option in ['name', 'repository', 'mainBranch', 'createBranch']:
            if option not in self.__configuration['mainProject']:
                print('Error: "%s" option is missing in the "mainProject" section in the configuration file' % option)
                sys.exit(1)

        for projectName in self.__configuration['projectsToMerge']:
            project = self.__configuration['projectsToMerge'][projectName]
            for option in ['repository', 'path', 'mainBranch', 'ignoreBranches']:
                if option not in project:
                    print('Error: "%s" option is missing in the "%s" project in the configuration file' %
                          (option, projectName))
                    sys.exit(1)


    def _rewrite_history(self, project_name):
        """
        Retwrites history for the project. This moves the project into its right
        place inside the main project.

        :param: project_name: project name to process
        """
        project = self.__configuration['projectsToMerge'][project_name]
        current_directory = os.getcwd()
        os.chdir(os.path.join(current_directory, project_name))

        command = "git filter-branch -f --tree-filter \"zsh -c 'setopt extended_glob && setopt glob_dots && mkdir -p %s && (mv ^(.git|%s) %s || true)'\" -- --all"
        first_dir = project['path'].split(os.path.sep)[0:1][0]
        self._execute_shell_command(command, project['path'], first_dir, project['path'])

        os.chdir(current_directory)


if __name__ == "__main__":
    merge = MergeGitProjects()
    merge.execute()

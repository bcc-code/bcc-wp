# Development model for WordPress with Kinsta

## Set up local environment

1. Install [DevKinsta](https://kinsta.com/devkinsta/download/)

2. Create a new project / Clone an existing project


## Set up the repository in GitHub

1. Create a new repository in the [bcc-code organization](https://github.com/bcc-code)<br>
_Note 1: this will contain only the editable files (themes and plugins) from WordPress_<br>
_Note 2: remember to give access to **BCC IT Publishing** with Admin role and add it to the [Publishing Team](https://github.com/orgs/bcc-code/projects/3) dashboard_

2. Go to the folder where your project is located (probably **C:/Users/[YourUser]/DevKinsta/public/[ProjectName]**) and add a _.gitignore_ file starting from [this template](https://github.com/bcc-code/bcc-wp/blob/master/kinsta-gitignore-template).<br>
_Note: that is to exclude everything else from WordPress which won't be edited during development_

3. Open a cmd in the project folder and run the following commands which add the created repository as a remote:<br>
`git init`<br>
`git add .`<br>
`git remote add production https://github.com/bcc-code/[project-name].git`

4. Go to the [Kinsta SSH key settings](https://github.com/organizations/bcc-code/settings/secrets/actions/KINSTA_SSH_KEY_PRIVATE) and add the new repository to the list.

5. Create a workflow in GitHub (which will deploy the code to the Kinsta server on every push) starting from [this template](https://github.com/bcc-code/bcc-wp/blob/master/kinsta-workflow-template.yml).
<br><br>
**Replace:**
- _REPLACE_THIS_WITH_KINSTA_PRODUCTION_PORT_
- _REPLACE_THIS_WITH_KINSTA_STAGING_PORT_
- _REPLACE_THIS_WITH_KINSTA_HOST_
- _REPLACE_THIS_WITH_PROJECT_NAME_IN_KINSTA_
- _REPLACE_THIS_WITH_PROJECT_ID_IN_KINSTA_
<br><br>
_Note: the necessary info can be found in [MyKinsta](https://my.kinsta.com/sites) and choosing the corresponding project_<br>


## Set up the connection from the Kinsta server to GitHub

_Note: Further down will you find the placeholder variable names you have to replace_

1. Get the ssh keys from the **Kinsta SSH** item. Create a file on your local, name it **kinsta**, add the content of the same private key and save it in a safe place (best is the C:/Users/[YourUser]/.ssh folder) and give it permission access (e.g. `chmod 400 kinsta`).<br>
Open a cmd and type in `ssh-add C:/Users/[YourUser]/.ssh/kinsta` to add the secret key to your OS registry.<br>

2. SSH into the Kinsta Server (a good idea is to use **Visual Code** with the **SSH Remote Server** extension)<br>
_Note: server details can be found in MyKinsta_

3. Run `cd private`, `git init --bare {sitename}.git`

4. Run `cd ../public`, `git init`

5. Access the private repo hooks folder and create a post-receive hook.<br>
`cd ../private/{sitename}.git/hooks`<br>
`nano post-receive`

6. Add the following code inside the post-receive file, make the necessary changes, save and quit nano (CTRL+X -> Y -> Enter).
<pre>#!/bin/bash
TARGET="/www/{sitefolder_xxxxxxx}/public"
GIT_DIR="/www/{sitefolder_xxxxxxx}/private/{sitename}.git"
BRANCH="main"

while read oldrev newrev ref
do
        # only checking out the main (or whatever branch you would like to deploy)
        if [[ $ref = refs/heads/$BRANCH ]];
        then
                echo "Ref $ref received. Deploying ${BRANCH} branch to production..."
                git --work-tree=$TARGET --git-dir=$GIT_DIR checkout -f
        else
                echo "Ref $ref received. Doing nothing: only the ${BRANCH} branch may be deployed on this server."
        fi
done</pre>

<br>_Replacements you have to make:_
- **{sitename}** is the project name in Kinsta (**Username** value in **MyKinsta**)
- **{sitefolder_xxxxxxx}** is the project name concatenated with the project id in Kinsta (found under the **Path** field in **MyKinsta**)

7. Assign execute permissions to post-receive file.<br>
`chmod +x post-receive`

8. Do the same thing for the staging environment, or push Live into Staging in Kinsta.


## Push to GitHub (will deploy to Kinsta)

When you now push to GitHub, the **kinsta.yml** workflow should be triggered.<br>
If the commit is to **develop** it will deploy the changes to the **staging** environment, and if it is **main** it will deploy to the **live** env.

Kinsta accepts the push from the GitHub action because the **BCC IT** account in Kinsta has the _kinsta_ public key added in Settings > SSH keys.


## Deploy to Kinsta from **DevKinsta**

You may also deploy the entire application (database, WordPress files, etc.) to Kinsta. To do so, you will:

1. _Push to staging_ from **DevKinsta**. This will take a few minutes.

2. Once that is done, you can deploy **staging** to **production** from **MyKinsta**.<br>
_Note: One disadvantage when deploying from **DevKinsta** is that you have to remove the registration of the production server from the known hosts_

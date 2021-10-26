# Development model for WordPress with Kinsta

## Set up local environment

1. Install [DevKinsta](https://kinsta.com/devkinsta/download/)

2. Create a new project / Clone an existing project


## Set up the repository in GitHub

1. Create a new repository in the [bcc-code organization](https://github.com/bcc-code)<br>
_Note 1: this will contain only the editable files (themes and plugins) from WordPress_<br>
_Note 2: remember to give access to **BCC IT Publishing** with Admin role and add it to the [Publishing Team](https://github.com/orgs/bcc-code/projects/3) dashboard_

2. Get the ssh keys from the **Kinsta SSH** item.
    1. Go to Settings > Secrets in GitHub and add a new repository secret called **KINSTA_SSH_KEY_PRIVATE** with the value of the private key<br>
    _Note: this step is required for private repositories while we are on a Free plan on GitHub_

    2. Create a file on your local, name it **kinsta** and save it in a safe place (best is the C:/Users/[YourUser]/.ssh folder) and give it permission access (e.g. `chmod 400 kinsta`).<br>
    Open a cmd and type in `ssh-add C:/Users/[YourUser]/.ssh/kinsta` to add the secret key to your OS registry.

3. Go to the folder where your project is located (probably **C:/Users/[YourUser]/DevKinsta/public/[ProjectName]**) and add the created repository as a remote.<br>
`git init`<br>
`git add .`<br>
`git remote add production https://github.com/bcc-code/[project-name].git`

4. Add a _.gitignore_ file starting from [this template](https://github.com/bcc-code/bcc-wp/blob/master/kinsta-gitignore-template).<br>
_Note: that is to exclude everything else from WordPress which won't be edited during development_

7. Create a workflow in GitHub (which will deploy the code to the Kinsta server on every push) starting from [this template](https://github.com/bcc-code/bcc-wp/blob/master/kinsta-workflow-template.yml).
<br><br>
**Replace:**
- _REPLACE_THIS_WITH_KINSTA_PRODUCTION_PORT_
- _REPLACE_THIS_WITH_KINSTA_STAGING_PORT_
- _REPLACE_THIS_WITH_KINSTA_HOST_
- _REPLACE_THIS_WITH_PROJECT_NAME_IN_KINSTA_
- _REPLACE_THIS_WITH_PROJECT_ID_IN_KINSTA_
<br><br>
_Note: the necessary info can be found in [MyKinsta](https://my.kinsta.com/sites) and choosing the corresponding project<br>


## Set up the connection from the Kinsta server to GitHub

1. SSH into the Kinsta Server (a good idea is to use **Visual Code** with the **SSH Remote Server** extension)
_Note: server details can be found in MyKinsta_

2. Run `git init --bare /private/{sitename}.git`

3. Run `git init /public`

4. Access the private repo hooks folder and create a post-receive hook.<br>
`cd /www/{sitefolder_xxxxxxx}/private/{sitename}.git/hooks`<br>
`nano post-receive`

5. Add the following code inside the post-receive file, make the necessary changes, save and quit nano (CTRL+X -> Y -> Enter).
<pre>#!/bin/bash
TARGET="/www/{sitefolder_xxxxxxx}/public"
GIT_DIR="/www/{sitefolder_xxxxxxx}/private/{sitename}.git"
BRANCH="master"

while read oldrev newrev ref
do
        # only checking out the master (or whatever branch you would like to deploy)
        if [[ $ref = refs/heads/$BRANCH ]];
        then
                echo "Ref $ref received. Deploying ${BRANCH} branch to production..."
                git --work-tree=$TARGET --git-dir=$GIT_DIR checkout -f
        else
                echo "Ref $ref received. Doing nothing: only the ${BRANCH} branch may be deployed on this server."
        fi
done</pre>

6. Assign execute permissions to post-receive file.<br>
`chmod +x post-receive`

Note down the following replacements you have to make:
- **{sitename}** is the project name in Kinsta (**Username** value in **MyKinsta**)
- **{sitefolder_xxxxxxx}** is the project name concatenated with the project id in Kinsta (found under the **Path** field in **MyKinsta**)


## Push to GitHub (will deploy to Kinsta)

When you now push to GitHub, the **kinsta.yml** workflow should be triggered.<br>
If the commit is to **develop** it will deploy the changes to the **staging** environment, and if it is **master** it will deploy to the **live** env.

Kinsta accepts the push from the GitHub action because the **BCC IT** account in Kinsta has the _kinsta_ public key added in Settings > SSH keys.


## Deploy to Kinsta from **DevKinsta**

You may also deploy the entire application (database, WordPress files, etc.) to Kinsta. To do so, you will:

1. _Push to staging_ from **DevKinsta**. This will take a few minutes.

2. Once that is done, you can deploy **staging** to **production** from **MyKinsta**.<br>
_Note: One disadvantage when deploying from **DevKinsta** is that you have to remove the registration of the production server from the known hosts_

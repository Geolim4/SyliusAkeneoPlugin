#!/usr/bin/env groovy

@Library("groovyFramework")

import com.synolia.log.Slack;
import com.synolia.system.Security;
import com.synolia.quality.GrumPhp;
import com.synolia.quality.PhpStan;
import com.synolia.quality.PhpUnit;

// Global
projectName = "Sylius Akeneo Plugin"
srcDir = "src"
binDir = "bin"
applicationDir = "tests/Application"
projectChannelName = "sylius-plugins-ci"
failedStage = "unknown"

// Docker
phpDockerRegistry = "registry.synocloud.com/php73dev:latest"
dbDockerRegistry = "registry.synocloud.com/percona57dev:latest"
seleniumRegistry = "selenium/standalone-chrome:3.141.59-europium"

// Get Slack Client
def slack = new Slack(this, "synolia", projectChannelName)
// Get Security tools
def security = new Security(this)

DOCKER_NETWORK = 'net-' + BUILD_TAG
BUILD_TAG = env.BUILD_TAG.replaceAll('%2F', '_')
JOB_NAME = env.JOB_NAME.replaceAll('%2F', '/')
DB_URL = "mysql://synbshop:Synolia01@db-" + BUILD_TAG + ":3306/sylius-akeneo-plugin"
COMPOSER_ARGS = '-e COMPOSER_HOME=$HOME/.composer -v $HOME/.composer/cache:$HOME/.composer/cache'
PHP_BUILD_ARGS = '--name=php-' + BUILD_TAG

pipeline {
    agent any
    options {
        durabilityHint('PERFORMANCE_OPTIMIZED')
    }
    stages {
        stage('Preparing Docker') {
            steps {
                script {
                    sh 'docker network create ' + DOCKER_NETWORK
                }
            }
            post { unsuccessful { script { failedStage = env.STAGE_NAME } } }
        }
        stage('Creating Selenium container') {
            steps {
                script {
                    selenium = docker.image(seleniumRegistry)
                    selenium.run('--name selenium_chrome-'+BUILD_TAG+' --volume /dev/shm:/dev/shm --network '+DOCKER_NETWORK)
                }
            }
            post { unsuccessful { script { failedStage = env.STAGE_NAME } } }
        }
        stage('Creating MySQL container') {
            steps {
                script {
                    db = docker.image(dbDockerRegistry)
                    db.run('--name db-'+BUILD_TAG+' --network '+DOCKER_NETWORK)
                }
            }
            post { unsuccessful { script { failedStage = env.STAGE_NAME } } }
        }
        stage('Create Php Container') {
            agent {
                docker {
                    image phpDockerRegistry
                    args "${PHP_BUILD_ARGS} ${COMPOSER_ARGS} --network "+DOCKER_NETWORK+" -e APP_ENV=test"
                    reuseNode true
                }
            }
            stages {
                stage('Preparing tools') {
                    steps {
                        script {
                            security.buildSshFolder()
                            // Credentials for Github
                            withCredentials([string(credentialsId: 'githubToken', variable: 'githubToken')]) {
                                sh "composer config -g github-oauth.github.com ${githubToken}"
                            }

                            // Credentials for BitBucket
                            withCredentials([usernamePassword(credentialsId: 'bitbucketToken', passwordVariable: 'token', usernameVariable: 'consumerKey')]) {
                                sh "composer config -g bitbucket-oauth.bitbucket.org ${consumerKey} ${token}"
                            }

                            // Composer install package as global
                            sh 'composer global require hirak/prestissimo ^0.3'
                        }
                    }
                    post { unsuccessful { script { failedStage = env.STAGE_NAME } } }
                }

                stage('Application Installation') {
                    steps {
                        script {
                            // Set database to .env
                            sh "echo DATABASE_URL=${DB_URL} >> ${applicationDir}/.env.test"

                            // Composer install
                            sshagent(['deploy_symfony']) {
                                sh "php -d memory_limit=-1 /usr/local/bin/composer install --no-interaction --prefer-dist"
                            }

                            sh "/usr/local/bin/composer patch"
                            sh "cp behat.yml.dist behat.yml"
                            sh "sed -i 's/localhost:8080/selenium_chrome-"+BUILD_TAG+":4444/g' behat.yml"
                            sh "sed -i 's|DB_URL|${DB_URL}|g' phpunit.xml.dist"
                            sh "cd ${applicationDir}; yarn install && yarn build"
                            sh "cd ${applicationDir}; php bin/console doctrine:database:create --env=test"
                            sh "cd ${applicationDir}; php bin/console doctrine:schema:create --env=test"
                            sh "cd ${applicationDir}; php bin/console sylius:fixtures:load -n --env=test"
                            sh "cd ${applicationDir}; php bin/console assets:install public --symlink"
                            sh "cd ${applicationDir}; php bin/console cache:warmup --env=test"
                        }
                    }
                    post { unsuccessful { script { failedStage = env.STAGE_NAME } } }
                }

                stage('Quality Tools') {
                    parallel {
                        stage('Grumphp') {
                            steps {
                                script {
                                    def grumPhp = new GrumPhp(this, 'vendor/bin/grumphp')
                                    grumPhp.run()
                                }
                            }
                            post { unsuccessful { script { failedStage = env.STAGE_NAME } } }
                        }

                        stage('Easy coding standard') {
                            steps {
                                script {
                                    sh "vendor/bin/ecs check src/ tests/Behat/ --no-progress-bar --config=ruleset/easy-coding-standard.yml"
                                }
                            }
                            post { unsuccessful { script { failedStage = env.STAGE_NAME } } }
                        }

                        stage('PhpStan') {
                            steps {
                                script {
                                    def phpStan = new PhpStan(this, 'vendor/bin/phpstan')
                                    phpStan.runOnDirectory(
                                        srcDir,
                                        "ruleset"
                                    )
                                }
                            }
                            post { unsuccessful { script { failedStage = env.STAGE_NAME } } }
                        }
                    }
                }

                stage('Testing') {
                    parallel {
                        stage('PhpUnit') {
                            steps {
                                script {
                                    def phpUnit = new PhpUnit(this, "vendor/bin/phpunit")
                                    phpUnit.runTest('Synolia-Akeneo-Plugin-Test-Suite', 'phpunit.xml.dist', ".");
                                }
                            }
                            post { unsuccessful { script { failedStage = env.STAGE_NAME } } }
                        }

                        stage('PhpSpec') {
                            steps {
                                script {
                                    sh "vendor/bin/phpspec run --no-code-generation --format=junit > junit_phpspec.xml"
                                    junit allowEmptyResults: true, testResults: "junit_phpspec.xml"
                                }
                            }
                            post { unsuccessful { script { failedStage = env.STAGE_NAME } } }
                        }

                        stage('Behat') {
                            steps {
                                script {
                                    sh "vendor/bin/behat -f pretty -o pretty.out -f progress -o std -f junit -o testreports"
                                    junit allowEmptyResults: true, testResults: "testreports/*.xml"
                                }
                            }
                            post { unsuccessful { script { failedStage = env.STAGE_NAME } } }
                        }
                    }
                }
            }
        }
    }
    post {
        success {
            script {
                def message = "SUCCESS :champagne: \n Build <${env.RUN_DISPLAY_URL}|#${env.BUILD_NUMBER}> >> ${JOB_NAME}."
                if (env.CHANGE_URL) {
                    message = message + "\n <${env.CHANGE_URL}|${env.BRANCH_NAME} (${CHANGE_BRANCH})> Let's go for code review."
                }

                slack.send(message, "#14892c")
            }
        }
        unsuccessful {
            script {
                def message = ":warning: ${currentBuild.result}: \n Build <${env.RUN_DISPLAY_URL}|#${env.BUILD_NUMBER}> failed at stage *${failedStage}* >> ${JOB_NAME}."
                if (env.CHANGE_URL) {
                    message = message + "\n <${env.CHANGE_URL}|${env.BRANCH_NAME} (${CHANGE_BRANCH})> needs to be fixed."
                }
                slack.send(message, "#e01716")
            }
        }
        always {
            cleanWs deleteDirs: true, notFailBuild: true
            sh 'docker stop --time=1 db-' + BUILD_TAG + ' || true'
            sh 'docker stop --time=1 selenium_chrome-' + BUILD_TAG + ' || true'
            sh 'docker rm -f db-' + BUILD_TAG + ' || true'
            sh 'docker rm -f selenium_chrome-' + BUILD_TAG + ' || true'
            sh 'docker network rm ' + DOCKER_NETWORK + ' || true'
        }
    }
}

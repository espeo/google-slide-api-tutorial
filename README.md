# Espeo Google Slides Generator (tutorial)
## Requirements
```
PHP 5.4+
composer //included in project
```
## Installation
`php composer.phar install`
## Define variables explanations
* **CREDENTIALS_PATH** - path to file where you store user access tokens
* **CLIENT_SECRET_PATH** - path where you store credentials downloaded from Google API Console
* **TEMPLATE_NAME** - the name of existing presentation which you use as a template
* **SCOPES** - scopes for permissions, which you need to grant from user to generate presentation on his/her account
* **DRIVE_API_FILES_ENDPOINT** - url endpoint for Google Drive API needed for creating private image urls
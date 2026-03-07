## Server 
- check the file upload max limit ?
- setup cron jobs to run every 15 minutes ?

## connection to side
- put the correct login details in settings
- test connection
  ```
  drush side:connect
  ```

## Cases
- reset case numbering
```
  ddev drush case_tweaks:renumber-ref-ids
```

## Translations

- download and upload greek translations
  ```
  # Export only customized (local overrides)
  ddev drush locale:export el --types=customized > custom-el.po
  ```

## General
- check /greek_holidays 


## Incoming / documents 
- check /settings/opinion_ref_id
- check settings have proper connection to side
- delete incoming ?
  ```
  ddev exec php scripts/deleteAllIncoming.php --limit=2 --yes
  ```
## notifications
- mark all notifications as read
  ```
  ddev drush sql:query "UPDATE notify_widget SET \`read\`=1 WHERE \`read\`=0;"  
  ```
- or remove all notifications
  ```
  ddev drush sql:query "TRUNCATE TABLE notify_widget;"
  ```

## users and passwords
- do we have all users in database ?
  ```
  ddev drush user-import:csv /var/www/html/artifacts/users.csv --delimiter=, --password='TempPass123'

  ```
- reset all passwords ?
  ```
  ddev drush users_tweaks:password-bulk
  ```

- set user password same as username
  ```
  ddev drush users_tweaks:password-set bardikoy_spyridoyla
  ```
- turn on or off the password reset (force password change)
  ```
  ddev drush users_tweaks:password-expire-set bardikoy_spyridoyla on
  ddev drush users_tweaks:password-expire-bulk off
  ddev drush users_tweaks:password-expire-bulk on
  ```
- add docutracks username for syncing
- can use following commands for checking
  ```
  drush users_tweaks:users --dt
  ```
- and syncing
  ```
  drush users_tweaks:users-sync-dt
  drush users_tweaks:users-sync-dt --force
  ```

## views export Checks

should produce no mismatch

```
ddev drush icex --view=incoming_amke --display=page_1 --query='sub=&year=2025&status=All'

ddev drush icex --view=incoming_amke --display=page_2 --query='sub=&year=2025&status=All&entry[min][date]=&entry[max][date]='

ddev drush icex --view=incoming_amke --query='refid=&sub=&type=All&status=processing'

ddev drush icext --view=incoming_amke --scenarios=7

ddev drush icext --scenarios=15
```

## gsis_pa_auth

Get key
```
$ drush cget oauth2_client.oauth2_client.kemke_gsis_pa --format=yaml

uuid: 355fc24e-1ff2-4d7b-aa9a-0be19aa81788
langcode: el
status: true
dependencies:
  module:
    - kemke_gsis_pa_oauth2_client
id: kemke_gsis_pa
label: 'KEMKE GSIS PA OAuth2'
description: ''
oauth2_client_plugin_id: kemke_gsis_pa
credential_provider: oauth2_client
credential_storage_key: 355fc24e-1ff2-4d7b-aa9a-0be19aa81788
```

Set credentials
```
$ drush php:eval '$key = "355fc24e-1ff2-4d7b-aa9a-0be19aa81788"; \Drupal::state()->set($key, ["client_id" => "THVRZH42954", "client_secret" => "Kemke2025!!"]); echo "ok\n";'
ok
```


```
$ drush php:eval '$client = \Drupal::service("oauth2_client.service")->getClient("kemke_gsis_pa"); var_export(["client_id" => $client->getClientId(), "client_secret_len" => strlen($client->getClientSecret())]);'

array (
  'client_id' => 'THVRZH42954',
  'client_secret_len' => 11,
)-
```

## Server 
- check the file upload max limit ?
- setup cron jobs to run every 15 minutes ?
  

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
  ddev exec php scripts/passwords.php pass:bulk 
  ddev exec php scripts/passwords.php pass:set bardikoy_spyridoy
  ```
- turn on or off the password reset (force password change)
  ```
  ddev exec php scripts/passwords.php expire:bulk off
  ddev exec php scripts/passwords.php expire:bulk on  
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

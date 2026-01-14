- reset case numbering
```
  ddev drush case_tweaks:renumber-ref-ids
```
- download and upload greek translations
- check /greek_holidays 
- check /settings/opinion_ref_id
- check settings have proper connection to side

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
- alsdjf
- 
#!/bin/sh

npm run wp-env run cli -- wp eval-file /var/www/html/wp-content/plugins/hyve-lite/bin/set-settings.php
npm run wp-env run tests-cli -- wp eval-file /var/www/html/wp-content/plugins/hyve-lite/bin/set-settings.php
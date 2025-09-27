#!/bin/bash

# Set proper permissions (wp-env should already be running via postenv:start hook)
npx wp-env run tests-wordpress chmod -c ugo+w /var/www/html

# Set up permalinks
npx wp-env run tests-cli wp rewrite structure '/%postname%/' --hard

# Create a blog page for testing
npx wp-env run tests-cli wp post create --post_type=page --post_title='Blog' --post_name=blog --post_status=publish

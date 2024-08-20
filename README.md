Symfony6-REST-API
=================

I've written this Symfony application to keep track of my stock portfolio.

Features
--------

- Add any stock available on Yahoo Finance
- Show daily wins/losses
- Show overall wins/losses
- Add stocks to watch
- Auto and forced refresh on the tabular view
- Privacy feature to hide sensitive information

Installation
------------

1) Configure Symfony environment variables, e.g. as an `.env.local` file (example can be found in `.env.dist`)
2) Install Composer dependencies: `composer install`
3) Initialize the database: `bin/console doctrine:schema:create`
4) Install Yarn dependencies: `yarn install`
5) Build production assets: `yarn build`


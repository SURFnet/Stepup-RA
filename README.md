Step-up Registration Authority
==============================

[![Build Status](https://travis-ci.org/SURFnet/Stepup-RA.svg)](https://travis-ci.org/SURFnet/Stepup-RA) [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/SURFnet/Stepup-RA/badges/quality-score.png?b=develop)](https://scrutinizer-ci.com/g/SURFnet/Stepup-RA/?branch=develop) [![SensioLabs Insight](https://insight.sensiolabs.com/projects/8f9557e9-d8b8-4625-9e2a-60587d3cb3f0/mini.png)](https://insight.sensiolabs.com/projects/8f9557e9-d8b8-4625-9e2a-60587d3cb3f0)

This component is part of "Step-up Authentication as-a Service" and requires other supporting components to function. See [Stepup-Deploy](https://github.com/SURFnet/Stepup-Deploy) for an overview. 

## Requirements

 * PHP 5.6+ or PHP7
 * [Composer](https://getcomposer.org/)
 * A web server (Apache, Nginx)
 * Graylog2 (or disable this Monolog handler)
 * A working [Gateway](https://github.com/SURFnet/Stepup-Gateway)
 * Working [Middleware](https://github.com/SURFnet/Stepup-Middleware)

## Installation

Clone the repository or download the archive to a directory. Install the dependencies by running `composer install`.

Run `app/console mopa:bootstrap:symlink:less` to configure Bootstrap symlinks.

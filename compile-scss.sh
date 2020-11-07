#!/bin/bash

sassc --precision 10 --style compact scss/ratings.scss css-compiled/ratings.min.css
sassc --precision 10 --style compact scss/ratings-font-awesome-5.scss css-compiled/ratings-font-awesome-5.min.css

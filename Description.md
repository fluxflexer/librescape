# Purpose

the expected reuslt is a web application to display a timeline in which the follwowing information will be displayed concurrently in a timeline:
* The events in the personal life of an author
* The gestation of different works and publications of the same author
* historical events and periods in the same timeframe
* other events and periods that can be defined by an administrator


# Features
The displayed timeline should be displayed in the browser with the following features:
* zoomable, scrollable timeline
* different timeline for biography, publications, world events etc. should be displayed concurrently
* Events and periods are clickable, displaying more information and keywords 
* search box for keywords, events, persons, periods
* data entry should be realized with a differnt, independant applications
* the presentation is smooth and beautiful 

# Stack
* Backend: apache webserver, php, MariaDB
* Frontend: Javascript, if possible no fancy frameworks, additional libraries are accepable

# Data repesentation
an suggestion for the initial data representation in the database can be found in the sub folder: "data_representation"

# Database and Webserver

the local web root:"C:\XAMPP\htdocs\librescape"
database: librescape
user: librescape
password: see config.php (local only, not in the repository; template: config.example.php) 
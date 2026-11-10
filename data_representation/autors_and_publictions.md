# Data Structure

## Person

* Person ID  | unique, Index
* first name
* surename
* date of birth
* place of birth
* date of death
* link to CV
* link wikipedia
* shortcode | unique

## Relationship

* Relationship ID   | unique, Index
* First Person ID   
* Second Person ID
* RelationType ID
* RelationStatus

## RelationType

* RelationType ID   | unique, Index
* ShortName
* Description

## Publication

* Publication ID    | unique, Index
* Author ID
* Author2 ID
* Author3 ID
* Title
* category ID
* short title
* ParentWork ID
* Description
* link to wikipedia
* Release Date
* shortcode

## Pubcategory

* PubCategory ID    | unique, Index
* category
* Short Title
* Description



# Import Script from Manictime to Aceproject

This script imports the data tracked from Manictime (CSV file) to AceProject.
This is a personal project and highly customized for my needs.
To configure it for your needs, just edit the `manic2ace.php` file.

### HOWTO
- First install dependencies by executing `composer install`
- copy `config.example.json` as `config.json` and add your AceProject credentials there.
- In ManicTime, export a month of data as CSV to the `data` folder.
- edit `manic2ace.php` and add there the filename of your CSV file. On the bottom of the file, uncomment the method you want to use. There are 2 steps involved here:
- execute the script to generate `prepare.json` (`php manic2ace.php`)
- Edit the prepare.json file and edit the aceproject stuff:

```json
    "aceproject": {
        "task_id": 0,
        "task_name": "TEST3",
        "task_project": "Intern",
        "comments": "Some comment"
    },
    "data": { ... }
```
`task_id` is `-1` by default, which means that nothing will be imported. Use `0` to automatically create the task in aceproject. Or use a real task_id from AceProject to use it as task.

If you are using `0` then you also need to fill out `task_name` and `task_project`.
task_name is the name of the task which will be created. And the `task_project` is the name of the project. It can also be a partial name of the project. The script will automatically get the correct (int) project_id.

You can also specify a comment. The comment will be saved in the time entry.

#### INFO
Project by Michael Milawski.
//2018-09-09
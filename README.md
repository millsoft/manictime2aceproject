# Import Script from Manictime to Aceproject

This script imports the data traced from aceproject to aceproject.
This is a personal project and highly customized for my needs.

### HOWTO
- First install dependencies by executing `composer install`
- copy `config.example.json` as `config.json` and add your AceProject credentials there.
- In ManicTime, export a month of data as CSV to the `data` folder.
- edit `manic2ace.php` and add there the filename of your CSV file.
- Execute the script to generate `prepare.json`
- Edit the prepare.json file and edit the aceproject stuff:

```json
            "aceproject": {
                "task_id": 0,
                "task_name": "TEST3",
                "task_project": "Intern"
            },
            "data": { ... }
```
`task_id` is -1 by default, which means that nothing will be imported. Use `0` to automatically generate the task in aceproject. Or use a real task_id from aceproject to use it.

If you are using `0` then you also need to fill out `task_name` and `task_project`.
task_name is the name of the task which will be created. And the `task_project` is the name of the project. It can also be a partial name of the project. The script will automatically get the correct (int) project_id.


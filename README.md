Simple PHP File Manager
===================

A very simple PHP file manager. All the code is contained in a single php file.   

## What's different in this fork?

- Delete Confirmation 
- Files to hide are configurable
- Supports spaces in file/folder name
- Shows folder size
- Fixed file size listing for files over 2GB (PHP limitation) 

## Key Features

- Single file, there are no images, or css folders
- Ajax based so it is fast, but doesn't break the back button
- Allows drag and drop file uploads if folder is writable by the webserver
- Simple and minimalistic UI. More like Dropbox, and less like Windows Explorer
- Works with Unicode file names
- The interface is usable from an iPad
- XSRF protection (though no authentication system)

## Getting Started 
Copy `index.php` to a folder on your web server.

## Screenshot

![Screenshot](https://raw.github.com/sonicer105/simple-php-file-manager/master/screenshot.png "Screenshot")

### Todo

- UI Enhancements

### Credits

- [jcampbell1](https://github.com/jcampbell1/simple-file-manager) for the original project
- [thinkdj](https://github.com/thinkdj/simple-php-file-manager) for deletion confirmations and file/folder hiding
- [sonicer105](https://github.com/sonicer105/simple-php-file-manager) for spaces in file/folder name support, folder size listing, and 2GB+ file size fix

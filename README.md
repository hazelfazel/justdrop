<h1>Uploading a file in chunks using PHP and Javascript XHR</h1>
<p>Back in the days we used FTP to upload files onto a server. With modern web technologies the task of uploading files does not require any additional applications like FTP, SSH, SCP etc. All you need is a basic webserver running PHP and a modern webbrowser. The keyword is XMLHttpRequest (XHR). It is a programming interface for JavaScript allowing you to transfer data via HTTP. Contrary to its name, this data does not have to be XML. It can be any data, including binary. XMLHttpRequest nowadays is a basic building block of the Ajax technology. AJAX (Asynchronous JavaScript and XML) describes a concept of asynchronous data transfer between a browser and the server. This allows such requests to be made while an HTML page is displayed. So the page can be modified without fully reloading it. E.g. think of facebook's webpage. It constantly gets updated within your browser, but you do not need to fully reload it e.g. using the F5 key.</p>
<p>You can use XHR to split a file upload into chunks. So you do not upload a file as one big POST request. I often had problems uploading big files using just POST. In addition most webservers do not allow uploading files greater >20 MBs using POST. This is why I created the script initially. Secondly I wanted to control what a user can upload. Request a password and send me notifications via email or messenger.</p>
<p>I ended up with a self contained PHP script named justdrop. It can easily be adjusted, I can specify a password, a fixed number of allowed uploads, time to live etc. I just modify some global variables. No big framework needed, everything is done in just one simple PHP file. I will publish it here under GNU General Public License 3, so you can do whatever your want.</p>
<p>I hope it can help you to easily share files with friends and colleagues without using services having questionable data protection policies. Think about where your uploaded files are hosted! If you are running your own server in your country you are safer than using services by big companies bound to a patriot acts and so. If you have any questions, please contact me. If you find a bug, please let me know. Thanks.</p>
<p>You can download the script here: <a href="justdrop.php" title="Just Drop chunked php file uploader">justdrop.php</a></p>
<h2>How to use the script?</h2>
<p>I recommend to create a separate direcotry for the script. You can name it e.g. ./justdrop or something. Place an empty index.html file in there. Now place copies of justdrop.php into this direcotry. Let's say your friend Joe wants to share something with you. Copy justdrop.php to joe-file-drop.php, define a password and share the link https://yourdomain.tld/justdrop/joe-file-drop.php. Joe can now upload a file using this personalized link. You might consider setting an upload counter, so Joe can only upload a limited number of files.</p>

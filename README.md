# RAWdez Photography

Website for RAWdez Photography (Bryan Ruadez), Tampa, FL: https://www.rawdez.com

A small, fast, no-database site built with plain HTML, CSS and JavaScript, plus a little PHP for the photo admin and the booking form. It runs on ordinary cPanel hosting.

## What is in it

| Path | What it does |
| --- | --- |
| `index.html` | Home page: hero, work cards, selected work, About, process, FAQ, booking form |
| `gallery.html` | Gallery of every *published* photo, with category filters and a viewer |
| `style.css` | All styling, in 23 labeled sections |
| `script.js` | Scrolling band, photo viewer, loads photos from `data/photos.json`, booking form |
| `contact.php` | Receives the booking form, emails the photographer, and keeps a backup copy |
| `admin/` | Password-protected photo admin (upload, swap homepage photos, crop, publish or hide, read inquiries) |
| `data/photos.json` | The photo list and which photo sits in each homepage spot (written by the admin) |
| `uploads/` | Web-sized photos (1600px) and thumbnails (`t/`), created by the admin |

## Design

Cream (`#efe6dd`) and cherry (`#9a0002`) with the Archivo typeface. Mobile first, with a floating "Book a shoot" button on phones.

## How the photo admin works

- The public pages never touch PHP. They read `data/photos.json` and fill each photo spot (`data-slot="..."` in `index.html`).
- The admin (`/admin/`) writes that file and the images in `uploads/`.
- Photos are shrunk in the browser before upload, then re-encoded on the server (EXIF and location data removed). Only JPG, PNG and WebP are accepted.
- Each photo is *Published* (shows on the gallery page) or *Hidden* (kept as a backup).
- Cropping is non-destructive: the admin stores a focus point and zoom per spot, so the original photo is never changed.

## Security notes

- Passwords are hashed (`password_hash`). The password file lives in `admin/private/`, which is blocked from the web by `.htaccess`.
- Sessions use HttpOnly and SameSite cookies, CSRF tokens, a 2 hour idle timeout, and a login limit of 5 misses per 15 minutes.
- The admin sends a strict Content Security Policy.
- The booking form has a hidden trap field, a minimum-time check, a same-site check, and a per-visitor hourly limit.
- No passwords, keys or setup codes are stored in this repository.

## Deploying

1. Upload everything to `public_html` (File Manager or FTP).
2. Open `admin/setup.php` and edit the `SETUP_CODE` line to your own random code **before** uploading it.
3. Visit `/admin/setup.php`, enter the code, and choose an admin password. The page deletes itself.
4. Make sure `data/`, `uploads/`, `uploads/t/` and `admin/private/` are writable (755 is normal on cPanel).
5. Log in at `/admin/` and add photos.
6. Change `ruadezphoto@gmail.com` in `contact.php` (the `TO_EMAIL` line) and in the page footers if you reuse this for another site.

After editing `style.css` or `script.js`, bump the `?v=` number in the `<link>` and `<script>` tags so browsers fetch the new file.

Save every file as **UTF-8**.

## Photos

The photographer's images are not included in this repository. The site shows grey "Photo goes here" placeholders until photos are added through the admin. `og-image.png` (the link preview picture) is also left out because it contains a photo; add your own 1200x630 image with that name.

## License

All rights reserved unless a license is added. Photographs belong to their photographer.

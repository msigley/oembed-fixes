# oembed-fixes
Wordpress plugin that fixes common issues with oEmbeds.

## Issues fixed
### Z-index issue
Prevents embeds from appearing on top of elements with a higher z-index.
### Prevents cookies from being set by oEmbed providers
YouTube embeds have their source url changed to www.youtube-nocookie.com. All other iframe embeds are marked to be sandboxed by the web browser. 
### Implements commonly used parameters for YouTube embeds
Prevents video suggestions, and fixes other performance and usability issues using the YouTube Iframe API.

## oEmbed provider list management
Downloads the most up to date oEmbed provider list from https://oembed.com/providers.json. This file can be updated by clicking a link in the admin area.
You can limit the providers available by using the ```'allowed_oembed_providers'``` filter.

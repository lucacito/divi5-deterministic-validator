#!/bin/sh
# DEV-ONLY. Usage: sh scripts/theme-packs/pexels.sh "<query>" [landscape|portrait|square] [count]
# Prints "id  WxH  alt" for Pexels search results (the API key is read from the layoutlab dev env inside this script and never printed).
# Pin a photo in a landing spec as px:<id> (width 1600) or px:<id>:<width>. Check the picture before you pin it (see the briefing).
key="$(grep '^PEXELS_API_KEY=' /Users/Lucas/Documents/JHMG-Local/layoutlab-tidewell/.env.local | cut -d= -f2- | tr -d '"')"
q="$(printf %s "$1" | sed 's/ /%20/g')"; o="${2:-landscape}"; n="${3:-12}"
curl -s -H "Authorization: $key" "https://api.pexels.com/v1/search?query=$q&orientation=$o&per_page=$n&size=large" |
  php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach(($j["photos"]??[]) as $p) printf("%-9d %5dx%-5d %s\n",$p["id"],$p["width"],$p["height"],substr($p["alt"]??"",0,90)); if(empty($j["photos"])) echo "no results / error\n";'

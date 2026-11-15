# Design sources

The SVGs are the source for the WordPress.org plugin assets. The PNGs in `.wordpress-org/` are generated from them. Edit the SVG, then regenerate the PNGs.

- `.wordpress-org/icon.svg`: the plugin icon. It lives in `.wordpress-org/` because WordPress.org reads `icon.svg` directly.
- `design/banner.svg`: the banner. WordPress.org only accepts PNG or JPG banners, so the source lives here.

## Regenerate the PNGs

Needs `rsvg-convert` (`brew install librsvg`). Run from the repo root:

```bash
rsvg-convert -w 128 -h 128 .wordpress-org/icon.svg -o .wordpress-org/icon-128x128.png
rsvg-convert -w 256 -h 256 .wordpress-org/icon.svg -o .wordpress-org/icon-256x256.png
rsvg-convert -w 772 -h 250 design/banner.svg -o .wordpress-org/banner-772x250.png
rsvg-convert -w 1544 -h 500 design/banner.svg -o .wordpress-org/banner-1544x500.png
```

## Keep `.wordpress-org/` clean

Everything in `.wordpress-org/` is copied to the public WordPress.org SVN assets folder when changes merge to `stable`. Keep PSDs, working files, and other sources out of it.

`design/` is left out of the plugin zip by `.distignore` and `.gitattributes`.

# AMF Unsplash

Bring Unsplash directly into your media library.


## About

AMF Unsplash uses the [Asset Manager Framework](https://github.com/humanmade/asset-manager-framework) to directly integrate Unsplash into your media library. This allows you to insert images anywhere they're used in WordPress, including Gutenberg, featured images, and the Customiser.

All the interesting functionality is provided by [AMF](https://github.com/humanmade/asset-manager-framework), and this plugin essentially acts as a demo of how to implement the framework.

## Installation

Install via Composer:

```sh
composer require humanmade/amf-unsplash
```

Alternatively, download this plugin and [Asset Manager Framework](https://github.com/humanmade/asset-manager-framework), and activate both.


## API Keys

Unsplash doesn't allow bundling API keys in open source software unfortunately, so you'll need to [register your own application](https://unsplash.com/documentation#registering-your-application).

(This isn't a great user experience, sorry!)

Once you have an API key, you can set it in Settings > Media.

Alternatively, you can set the `AMFUNSPLASH_API_KEY` constant to your API key. This allows network admins or hosts to set the API key automatically.


## License

Copyright 2020 Human Made. Licensed under the GPLv2 or later.

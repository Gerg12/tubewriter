<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
return json_decode( <<<'JSON'
{
  "currency": "USD",
  "billing_period": "month",
  "status": "preview",
  "default_track": "byok",
  "plans": [
    {
      "id": "free",
      "name": "Free",
      "price": 0,
      "price_hosted": 0,
      "price_byok": 0,
      "writing_credits": 3,
      "thumbnail_credits": 0,
      "channels": 1,
      "description": "Find your rhythm. Give your next few uploads a better first draft.",
      "features": [
        "3 writing credits, once, not monthly",
        "Titles, descriptions, chapters, and tags",
        "Your channel library and manual edits",
        "Calendar, review, publish, and restore",
        "No AI-generated thumbnail credits",
        "1 connected channel",
        "Current access uses your own AI key"
      ],
      "writing_period": "once"
    },
    {
      "id": "creator",
      "name": "Creator",
      "price": 2.99,
      "price_hosted": 4.99,
      "price_byok": 2.99,
      "writing_credits": 25,
      "thumbnail_credits": 0,
      "channels": 1,
      "description": "A steady writing partner for a channel that keeps creating.",
      "features": [
        "25 writing credits each month",
        "Titles, descriptions, chapters, and tags",
        "Your saved channel voice and prompts",
        "All core workspace tools",
        "No AI-generated thumbnail credits",
        "1 connected channel"
      ],
      "writing_period": "month"
    },
    {
      "id": "studio",
      "name": "Studio",
      "price": 9.99,
      "price_hosted": 14.99,
      "price_byok": 9.99,
      "writing_credits": 100,
      "thumbnail_credits": 10,
      "channels": 1,
      "description": "From the first words to the first impression. Your full creative toolkit.",
      "features": [
        "100 writing credits each month",
        "10 thumbnail credits each month",
        "AI thumbnail concepts and image generation",
        "Use screenshots and logos as references",
        "Everything in Creator",
        "1 connected channel"
      ],
      "writing_period": "month"
    }
  ],
  "thumbnail_pack": {
    "price": 4.99,
    "credits": 10,
    "studio_only": true,
    "hosted_only": true,
    "status": "planned"
  },
  "standard_thumbnail_credits": 1,
  "high_detail_thumbnail_credits": 3,
  "quota": {
    "daily_cap": 10000,
    "full_video_units": 100,
    "free_creator_full_per_day": 1,
    "studio_full_per_day": 2
  }
}

JSON
, true );

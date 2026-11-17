<?php

// Overrides for laravel/mcp (other keys come from the package's config).
return [

    // OAuth clients may only register redirect URIs on these domains: Claude (claude.ai,
    // Claude Desktop) and local callbacks on any port (Claude Code).
    'redirect_domains' => [
        'https://claude.ai',
        'https://claude.com',
        'http://localhost',
        'http://127.0.0.1',
    ],

];

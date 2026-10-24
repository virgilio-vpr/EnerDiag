# API documentation exports

`openapi.yaml` is the OpenAPI 3.0.3 specification. `postman_collection.json` is a Postman 2.1 collection. Both are generated from the Laravel routes and Scribe attributes.

To regenerate the interactive documentation, OpenAPI specification, and Postman collection:

```sh
cd backend
php artisan scribe:generate
```

The interactive docs are served at `/docs`, with live exports at `/docs.openapi` and `/docs.postman`. Copy the generated files from `storage/app/private/scribe/` into this directory when updating the checked-in exports.

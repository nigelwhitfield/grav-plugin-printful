# Custom fields and the settings store

Two ways for an add-on or a merchant to store extra data: typed fields attached to a product, an order or a customer, and untyped scoped settings that belong to a plugin. This page is for add-on developers and for merchants who want a field the built-in ones do not cover.

## Which one you want

| | Custom field | Settings store |
| --- | --- | --- |
| Attached to | One row: a product, a variant, an order, an order line, a customer, an address | A plugin, not a row |
| Typed | Yes — `text`, `textarea`, `integer`, `boolean`, `select`, `json` | No, values are stored as JSON |
| Reaches Twig | Yes, unless `internal` | No |
| Sorts and filters the admin list | Yes, when you say so | No |
| Adds database columns | No — two tables shipped in migration 0047 hold every field on every store | No — one namespaced row per key |
| Per admin user | No | Yes, with `scope: user` |
| Registered through | `onKahunaCartRegisterCustomFields` | `onKahunaCartRegisterSettingsNamespaces` |

A warranty period on a product is a custom field. A warranty add-on's API key is a settings-store key.

## Custom fields

### Visibility

Visibility decides where a value travels. It is the whole reason the field is typed rather than a blob in `data_json`.

| Visibility | Twig | The admin | Writable through the API |
| --- | --- | --- | --- |
| `public` | Yes | Yes | Yes |
| `readonly` | Yes | Yes | No — a write is refused with a 422 naming the field |
| `internal` | **Never** | Yes | Yes |

An `internal` field never reaches a template. `kahunacart_custom_fields()` filters it out and the `custom_fields` variable on the product page does not carry it. The guarantee is enforced in one place, `CustomFieldValues::forTemplate()`, and the suite asserts it by rendering every storefront template with an internal value set and searching the output for the value, its default and its name.

The storefront JSON API carries no custom fields at all today. An add-on that wants its own field in a JSON response adds it through `onKahunaCartExtendStorefrontSchema`, and is then responsible for what it puts there. See [Storefront API](storefront-api.md#add-your-own-keys-to-a-response).

> [!IMPORTANT]
> `internal` keeps a value out of templates. It is not a permission: an admin user with `kahunacart.orders.view` can read internal values through `GET /kahunacart/custom-fields/values/{entity}/{entityId}`. Do not put a secret in one; put it in the settings store behind a permission of your own.

### Types and their validation

| Type | Stored in | `validate` keys it understands |
| --- | --- | --- |
| `text` | `value_text` | `max_length` (default 190), `pattern` (a regular expression, no delimiters) |
| `textarea` | `value_json` | `max_length` (unlimited by default), `pattern` |
| `integer` | `value_int` | `min`, `max` |
| `boolean` | `value_int` | none |
| `select` | `value_text` | `options` — required, a list of values or a map of value to label |
| `json` | `value_json` | none |

Only `text` and `select` are stored in `value_text`, which is the indexed column, so only those two sort and filter as strings. An `integer` and a `boolean` sort on `value_int`, which is indexed as well. A `json` or `textarea` field cannot be `sortable` or `filterable`.

A value that fails its validation is refused with a sentence naming the field, and nothing on the row is written — a bad third field leaves the first two alone.

### Declare a field from an add-on

Subscribe to `onKahunaCartRegisterCustomFields` and declare onto the registry. A declared field is written into `kahunacart_custom_fields` on the first request that reads it, so it persists for merchants who later switch the add-on off.

```php
public static function getSubscribedEvents(): array
{
    return ['onKahunaCartRegisterCustomFields' => ['onKahunaCartRegisterCustomFields', 0]];
}

public function onKahunaCartRegisterCustomFields(Event $event): void
{
    $event['registry']->declare([
        'entity' => 'product',
        'name' => 'warranty_months',
        'type' => 'integer',
        'label_key' => 'PLUGIN_KAHUNACART_WARRANTY.MONTHS',
        'visibility' => 'public',
        'sortable' => true,
        'filterable' => true,
        'default' => 12,
        'validate' => ['min' => 0, 'max' => 120],
        'position' => 10,
    ], 'kahunacart-warranty');
}
```

| Key | Required | Notes |
| --- | --- | --- |
| `entity` | yes | `product`, `variant`, `order`, `order_item`, `customer`, `address`, or one you registered |
| `name` | yes | `[a-z][a-z0-9_]*`, unique on the entity. First declaration of a name wins. |
| `type` | no | Default `text`. It cannot change after values exist. |
| `label_key` | no | A translation key or a plain string. Falls back to the name. |
| `help_key` | no | The sentence under the control |
| `visibility` | no | Default `public` |
| `sortable`, `filterable` | no | Default false. Both are backed by an index, so saying yes costs nothing at query time. |
| `required` | no | Default false |
| `default` | no | The value a row starts with |
| `validate` | no | The keys the type understands, from the table above |
| `position` | no | The order on the admin form. Default 0. |

The second argument is your plugin's slug. It is recorded on the definition so the admin can say whose field it is.

### Register an entity of your own

A field can hang off a table your own add-on ships. Register the entity first, in the same listener, before declaring fields on it.

```php
$event['registry']->registerEntity('subscription', 'Subscription', 'kahunacart_subscriptions', 'kahunacart-subscriptions');
```

### Read values in Twig

`kahunacart_custom_fields(entity, id)` answers a `CustomFieldValues` object carrying every value a template may see. Iterate it, index it, or call a typed accessor.

```twig
{% for name, value in kahunacart_custom_fields('product', product.id) %}
    <div>{{ name }}: {{ value }}</div>
{% endfor %}

{{ kahunacart_custom_fields('product', product.id).int('warranty_months') }}
{{ kahunacart_custom_fields('order', order.id).text('gift_message') }}
```

| Method | Answers |
| --- | --- |
| `get(name)` | The value, or the field's default when nothing is stored |
| `text(name)` | The value as a string, `''` when unset |
| `int(name)` | The value as an integer, `0` when unset |
| `bool(name)` | The value as a boolean |
| `json(name)` | The value as an array |
| `has(name)` | Whether the field is declared and visible here |
| `isSet(name)` | Whether a value is stored, as opposed to defaulted |

The product page already carries `custom_fields` for the product being shown, and `templates/partials/kahunacart-custom-fields.html.twig` prints them as a definition list. Override that partial in your theme to change how they look.

### Sort and filter the admin product list

Set `sortable` or `filterable` on a field and it becomes a column of `GET /kahunacart/products`.

- `?sort=cf:warranty_months` orders by it, `?sort=cf:warranty_months:desc` the other way.
- `?cf[warranty_months]=24` narrows to the rows holding that value. Several `cf[…]` parameters are combined with AND.

A text search wins over both, because the search engines order by rank. The `total` in the answer counts the narrowed set, not the whole catalog.

### Manage fields in the admin

1. Go to **KahunaCart → Extensions**. The **Custom fields** panel lists every field on the store, with the plugin that declared it or *This store* for one made here.
2. Select **New field**.
3. Choose the entity, then fill in the name, type, label and visibility. Tick **Sortable in admin lists** or **Filterable in admin lists** if the list should order or narrow by it.
4. Select **Create field**.

The field now appears on the detail form for every row of that entity, in the **Custom fields** panel beside the record.

Editing a field changes its label, help, visibility, flags, default, validation and position. The entity, the name and the type are fixed once the field exists, because the values already stored were written against them.

A field an add-on declared can be edited but not deleted, because it comes back on the next request. Delete is offered only for a field made on this store.

### The demo catalog declares five

`bin/plugin kahunacart seed` writes five fields across three entities, so a fresh store shows what the feature does rather than an empty panel on every record: `warranty_months` (integer, public, sortable and filterable), `supplier_code` (text, **internal**), `care_instructions` (textarea, public), `gift_message` on an order, and `vat_number` (text, **readonly**) on a customer. Every seeded product answers the first two, the clothing answers the third, and `seed --account` writes the VAT number on the demo customer and a gift message on its delivered order.

They are declared with no owning plugin, exactly as a field typed into this screen is, so they can be deleted and `seed --purge` can take them back out — but only when no value of yours is still stored under one. Values are written through each field's own validation, the same call the API makes; `vat_number` is written the way an add-on writes a computed field, because readonly means the *admin API* may not write it. See [CLI → seed](cli.md#seed) and `seed/README.md`.

> [!CAUTION]
> Deleting a custom field deletes every value stored under it, on every row of its entity. The answer says how many values went with it, and there is no undo.

### The endpoints

| Method | Path | Permission |
| --- | --- | --- |
| `GET` | `/kahunacart/custom-fields` | `kahunacart.settings` |
| `POST` | `/kahunacart/custom-fields` | `kahunacart.settings` |
| `PATCH` | `/kahunacart/custom-fields/{id}` | `kahunacart.settings` |
| `DELETE` | `/kahunacart/custom-fields/{id}` | `kahunacart.settings` |
| `GET` | `/kahunacart/custom-fields/values/{entity}/{entityId}` | `kahunacart.orders.view` |
| `PUT` | `/kahunacart/custom-fields/values/{entity}/{entityId}` | The permission that writes the row |

Defining a field is configuration, so the four definition routes take `kahunacart.settings`. A value belongs to the row it sits on, so writing one takes `kahunacart.products.manage` for a product or variant, `kahunacart.orders.manage` for an order or a line, `kahunacart.customers.manage` for a customer or an address, and `kahunacart.settings` for an entity an add-on registered.

The MCP tools are `list_custom_fields`, `create_custom_field`, `update_custom_field`, `delete_custom_field`, `read_custom_field_values` and `write_custom_field_values`. See [Admin API and MCP tools](admin-api.md).

## The settings store

The settings store is plugin-scoped configuration with no database columns of its own. Everything lives in `kahunacart_settings`, under a `namespace` column, so two add-ons writing `api_key` are two rows that never meet.

### Register a namespace

```php
public static function getSubscribedEvents(): array
{
    return ['onKahunaCartRegisterSettingsNamespaces' => ['onKahunaCartRegisterSettingsNamespaces', 0]];
}

public function onKahunaCartRegisterSettingsNamespaces(Event $event): void
{
    $event['registry']->register('warranty', [
        ['name' => 'api_key', 'scope' => 'global', 'permission' => 'kahunacart.settings'],
        ['name' => 'default_months', 'scope' => 'global', 'default' => 12, 'validate' => static function ($value): int {
            $months = (int)$value;
            if ($months < 0 || $months > 120) {
                throw new \InvalidArgumentException('Between 0 and 120 months.');
            }

            return $months;
        }],
        ['name' => 'collapsed', 'scope' => 'user', 'default' => false, 'permission' => 'kahunacart.orders.view'],
    ], 'kahunacart-warranty');
}
```

| Key | Required | Notes |
| --- | --- | --- |
| `name` | yes | `[a-z][a-z0-9_]*`, optionally dotted |
| `scope` | no | `global` (one value for the store) or `user` (one per admin user). Default `global`. |
| `permission` | no | Checked per key on read and on write. Default `kahunacart.settings`. |
| `default` | no | What a read answers before anything is written |
| `validate` | no | A callable handed the incoming value that answers the value to store, or throws `\InvalidArgumentException` with a sentence the admin shows |

The namespace itself is `[a-z0-9][a-z0-9-]*`. First registration of a namespace wins; a second plugin claiming it is refused and logged.

### Read and write

```php
$store = KahunaCart::instance()->settingsStore();

$key = $store->get('warranty', 'api_key');
$store->set('warranty', 'default_months', 24);
$store->set('warranty', 'collapsed', true, $username);   // a user-scoped key needs the user
$all = $store->all('warranty', $username);
```

A write is one `INSERT … ON CONFLICT (namespace, name) DO UPDATE`. There is no read-then-write, so two workers saving the same key at the same moment both land and the later one wins.

A user-scoped key folds the username into the stored row name rather than into a column, which is how the per-user tier costs no schema.

### The endpoints

| Method | Path | Permission |
| --- | --- | --- |
| `GET` | `/kahunacart/settings-store/{namespace}` | `kahunacart.orders.view`, then each key's own |
| `PUT` | `/kahunacart/settings-store/{namespace}` | `kahunacart.orders.view`, then each key's own |

A read answers only the keys the caller may see. A write that names a key the caller may not change is refused whole with a 403 naming it, before anything is written; a value its validator rejects is refused whole with a 422. A namespace nobody registered is a 404, not an empty answer.

The MCP tools are `read_settings_store` and `write_settings_store`.

The base plugin registers three namespaces of its own. `layout` holds each zone's widget order and each admin user's dev-mode switch — see [Extension zones](extension-zones.md). `notifications` holds one key per notification code, carrying whether that message goes out and which template pair it renders from — see [Emails → Choosing which emails go out](emails.md#choosing-which-emails-go-out). `object-templates` holds one key per customised slot — see [Object templates](object-templates.md). All three grow a key at a time as zones, notifications and slots are registered, which is what `addKey()` is for; an add-on's namespace is free to do the same.

### Writing `kahunacart_settings` directly

If your add-on writes this table without the settings store, upsert through `Grav\Plugin\KahunaCart\Database\SettingsTable::upsert()` rather than by hand. Migration 0047 moved the table's unique key from `(name)` to `(namespace, name)`, and an upsert has to name a conflict target that matches an existing index on SQLite and PostgreSQL. `SettingsTable` decides which target applies from whether the column is there yet, so the same code works on a store mid-migration.

## Related

- [Extension zones](extension-zones.md) — where an add-on mounts a widget, and how the merchant orders them
- [Provider developer guide](provider-developer-guide.md) — events, generators and the test harness
- [Admin API and MCP tools](admin-api.md) — every admin route with its tool and permission
- [Storefront](storefront.md) — the Twig functions a theme can call

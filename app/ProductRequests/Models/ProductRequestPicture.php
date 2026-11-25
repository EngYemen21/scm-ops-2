<?php

namespace App\ProductRequests\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A picture of the product a request asks for. `status` says where its content lives: `stored` (database) /
 * `uploaded` (object store) for a picture added in OPS — both through FileStore — and `odoo` for a picture that was
 * attached in Odoo, whose content is read from the Odoo file archive (`odoo_files`) once it is kept there.
 */
class ProductRequestPicture extends BaseModel
{
    use HasUlids;

    protected $table = 'product_request_pictures';

    const UPDATED_AT = null;

    public const ODOO = 'odoo';

    /** Where a file lives is nobody's business outside the server. */
    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'removed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}

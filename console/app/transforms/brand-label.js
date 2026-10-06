import Transform from '@ember-data/serializer/transform';
import config from '@fleetbase/console/config/environment';

/**
 * Values the API generates or seeds with the upstream brand: the type label of
 * platform-wide roles and policies, the seeded developer role, and the seeded
 * administrator policy. They are re-created on every deploy (`fleetbase:create-permissions`),
 * and a role's name identifies it, so they are relabelled for display here rather than
 * renamed in the database. Only these exact values change, and saving a record sends the
 * API's own value back.
 *
 * Each entry is [value the API sends, value shown]; the first entry for a shown value is
 * what is sent back.
 */
function labels(brand) {
    return [
        ['FLB Managed', `${brand} Managed`],
        ['Fleetbase Managed', `${brand} Managed`],
        ['Fleetbase Developer', `${brand} Developer`],
        ['Policy for full access to Fleetbase extensions and resources.', `Policy for full access to ${brand} extensions and resources.`],
    ];
}

export default class BrandLabelTransform extends Transform {
    labels = labels(config.APP.brandName);

    deserialize(serialized) {
        const entry = this.labels.find(([fromApi]) => fromApi === serialized);
        return entry ? entry[1] : serialized;
    }

    serialize(deserialized) {
        const entry = this.labels.find(([, shown]) => shown === deserialized);
        return entry ? entry[0] : deserialized;
    }
}

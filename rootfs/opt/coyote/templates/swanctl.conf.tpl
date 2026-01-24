# Coyote Linux StrongSwan swanctl configuration
# Generated from template - do not edit manually

connections {
{{#tunnels}}
    {{name}} {
        version = {{version}}
        local_addrs = {{local_address}}
        remote_addrs = {{remote_address}}
        {{#proposals}}
        proposals = {{proposals}}
        {{/proposals}}

        local {
            auth = {{local_auth}}
            {{#local_id}}
            id = {{local_id}}
            {{/local_id}}
        }

        remote {
            auth = {{remote_auth}}
            {{#remote_id}}
            id = {{remote_id}}
            {{/remote_id}}
        }

        children {
            {{name}} {
                {{#local_ts}}
                local_ts = {{local_ts}}
                {{/local_ts}}
                {{#remote_ts}}
                remote_ts = {{remote_ts}}
                {{/remote_ts}}
                start_action = {{start_action}}
                {{#dpd_action}}
                dpd_action = {{dpd_action}}
                {{/dpd_action}}
            }
        }
    }
{{/tunnels}}
}

secrets {
{{#tunnels}}
{{#psk}}
    ike-{{name}} {
        {{#remote_id}}
        id = {{remote_id}}
        {{/remote_id}}
        secret = "{{psk}}"
    }
{{/psk}}
{{/tunnels}}
}

PHP 8.1.2 or higher required
composer2 required
team-reflex/discord-php ^10.7 required.

Need to build all tables in MySQL
* account           
* api_key           
* api_log           
* api_role          
* api_token         
* character_data    
* character_pet_data
* defines           
* message_queue     

Load api_key 1 with id/secret api@localhost 1000,1000,0
Fetch token with /auth/token call (need to temporarily disable id:1 token prevention in authcontroller)

Load api_roles with:
| discord-log | {"privilege":[{"controller":"LogController","function":"any","method":"any"}]} |
| super-admin | {"privilege":[{"controller":"any","function":"any","method":"any"}]}           |


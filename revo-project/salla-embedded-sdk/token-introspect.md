# Token Introspect

## OpenAPI Specification

```yaml
openapi: 3.0.1
info:
  title: ''
  description: ''
  version: 1.0.0
paths:
  /exchange-authority/v1/introspect:
    post:
      summary: Token Introspect
      deprecated: false
      description: >
        This endpoint exchanges short-lived Salla tokens for verified merchant
        identity to ensure backend request authenticity.
      tags:
        - Embedded SDK/Endpoints
      parameters:
        - name: s-source
          in: header
          description: The app id of your app
          required: true
          example: '12311221'
          schema:
            type: string
      requestBody:
        content:
          application/json:
            schema:
              type: object
              properties:
                token:
                  type: string
                  description: >-
                    The short-lived token from the URL when the Salla render
                    your App in the dashboard
              required:
                - token
              description: Authentication object containing the short-lived token
              x-apidog-orders:
                - token
              x-apidog-ignore-properties: []
            example:
              token: >-
                v4.public.eyJkYXRhIjp7Im1lc3NhZ2UiOiJIZWxsbyBTYWxsYSIsImFkZGl0aW9uYWwiOiJTb21lIGFkZGl0aW9uYWwgZGF0YSBhYm91dCB0aGUgc3RvcmUiLCJzY29wZXMiOlsiVGVzdCIsInRlc3QyIl19LCJleHAiOiIyMDI0LTEyLTMxVDIzOjU5OjU5WiIsImF1ZCI6ImFwcGxpY2F0aW9uQiIsImlzcyI6ImFwcGxpY2F0aW9uQSIsInN1YiI6ImRhdGFFbmNyeXB0aW9uIiwiaWF0IjoiMjAyNC0xMC0xM1QxMDoxMTo1Ny4xNDdaIn2wrbKlfemQ-cECbJxQfr4P9vZx91GgNMN66qdFnAEZwvq5rCPqKKjuhNSKpeInTti1ajUt_brgmuPUSpOmh0AP
      responses:
        '200':
          description: ''
          content:
            application/json:
              schema:
                properties:
                  status:
                    type: integer
                    description: HTTP status code indicating the result of the request
                    x-apidog-mock: '200'
                  success:
                    type: boolean
                    description: Boolean flag indicating whether the request was successful
                    x-apidog-mock: 'true'
                  data:
                    type: object
                    description: Response data containing merchant and user information
                    properties:
                      merchant_id:
                        type: integer
                        description: Unique identifier for the merchant (Store)
                        x-apidog-mock: '12345'
                      user_id:
                        type: integer
                        description: >-
                          Unique identifier for the current user trying to
                          access to the app
                        x-apidog-mock: '67890'
                      exp:
                        type: string
                        description: Expiration timestamp or date for the session or token
                        x-apidog-mock: '2024-12-31T23:59:59Z'
                    required:
                      - merchant_id
                      - user_id
                      - exp
                    x-apidog-orders:
                      - merchant_id
                      - user_id
                      - exp
                required:
                  - status
                  - success
                  - data
                x-apidog-orders:
                  - status
                  - success
                  - data
                $ref: '#/components/schemas/IntrospectSuccessResponse'
              examples:
                '1':
                  summary: Success
                  value:
                    status: 200
                    success: true
                    data:
                      merchant_id: 123456
                      user_id: 987654
                      exp: '2026-01-19T12:00:00Z'
                '2':
                  summary: Example 1
                  value:
                    status: 401
                    success: false
                    error:
                      message: Decryption failed
                      code: 0
          headers: {}
          x-apidog-name: OK
        '401':
          description: ''
          content:
            application/json:
              schema:
                properties:
                  status:
                    type: integer
                    description: HTTP status code of the response
                    x-apidog-mock: '@integer(400, 599)'
                  success:
                    type: boolean
                    description: Indicates whether the request was successful
                    x-apidog-mock: 'false'
                  error:
                    type: object
                    description: >-
                      Error information object containing details about the
                      failure
                    properties:
                      message:
                        type: string
                        description: >-
                          Human-readable error message describing what went
                          wrong
                        x-apidog-mock: '@sentence(5, 15)'
                      code:
                        type: integer
                        enum:
                          - 0
                          - 1
                          - 2
                          - 3
                          - 4
                        description: Error code indicating the type of error (0-4)
                        x-apidog-mock: '@integer(0, 4)'
                    required:
                      - message
                      - code
                    x-apidog-orders:
                      - message
                      - code
                required:
                  - status
                  - success
                  - error
                x-apidog-orders:
                  - status
                  - success
                  - error
                $ref: '#/components/schemas/IntrospectFailedResponse'
          headers: {}
          x-apidog-name: Unauthorized
      security: []
      x-apidog-folder: Embedded SDK/Endpoints
      x-apidog-status: released
      x-run-in-apidog: https://app.apidog.com/web/project/451700/apis/api-27474794-run
components:
  schemas:
    IntrospectSuccessResponse:
      type: object
      properties:
        status:
          type: integer
          description: HTTP status code indicating the result of the request
          x-apidog-mock: '200'
        success:
          type: boolean
          description: Boolean flag indicating whether the request was successful
          x-apidog-mock: 'true'
        data:
          type: object
          description: Response data containing merchant and user information
          properties:
            merchant_id:
              type: integer
              description: Unique identifier for the merchant (Store)
              x-apidog-mock: '12345'
            user_id:
              type: integer
              description: >-
                Unique identifier for the current user trying to access to the
                app
              x-apidog-mock: '67890'
            exp:
              type: string
              description: Expiration timestamp or date for the session or token
              x-apidog-mock: '2024-12-31T23:59:59Z'
          required:
            - merchant_id
            - user_id
            - exp
          x-apidog-orders:
            - merchant_id
            - user_id
            - exp
          x-apidog-ignore-properties: []
      required:
        - status
        - success
        - data
      x-apidog-orders:
        - status
        - success
        - data
      x-apidog-ignore-properties: []
      x-apidog-folder: ''
    IntrospectFailedResponse:
      type: object
      properties:
        status:
          type: integer
          description: HTTP status code of the response
          x-apidog-mock: '@integer(400, 599)'
        success:
          type: boolean
          description: Indicates whether the request was successful
          x-apidog-mock: 'false'
        error:
          type: object
          description: Error information object containing details about the failure
          properties:
            message:
              type: string
              description: Human-readable error message describing what went wrong
              x-apidog-mock: '@sentence(5, 15)'
            code:
              type: integer
              enum:
                - 0
                - 1
                - 2
                - 3
                - 4
              description: Error code indicating the type of error (0-4)
              x-apidog-mock: '@integer(0, 4)'
          required:
            - message
            - code
          x-apidog-orders:
            - message
            - code
          x-apidog-ignore-properties: []
      required:
        - status
        - success
        - error
      x-apidog-orders:
        - status
        - success
        - error
      x-apidog-ignore-properties: []
      x-apidog-folder: ''
  securitySchemes:
    bearerAuth:
      type: bearer
      scheme: bearer
    bearerAuth1:
      type: jwt
      scheme: bearer
      bearerFormat: JWT
    api_key:
      type: apikey
      name: api_key
      in: header
    petstore_auth:
      type: oauth2
      flows:
        implicit:
          authorizationUrl: https://petstore.swagger.io/oauth/authorize
          scopes:
            read:pets: read your pets
            write:pets: modify pets in your account
    oauth21:
      type: oauth2
      flows:
        authorizationCode:
          authorizationUrl: https://accounts.salla.sa/oauth2/auth
          tokenUrl: ' https://accounts.salla.sa/oauth2/token '
          refreshUrl: ''
          scopes:
            offline_access: ''
          x-apidog:
            addTokenTo: header
            useTokenType: access_token
            queryParamKey: access_token
            headerPrefix: Bearer
            state: '12345678'
            redirectUri: https://app.apidog.com/oauth2-browser-callback.html
            clientId: 8a6c31ed-b841-48ca-85a9-3596e4d60d01
            clientSecret: 1c9c66a597ec06c7ab9cd89d9d4e9a8bed005e6ee279e35ede14f905170b1ea5
            codeVerifier: ''
            challengeAlgorithm: S256
            clientAuthentication: body
            useTokenConfigAsRefreshTokenConfig: true
            authRequestParams: []
            tokenRequestParams: []
servers:
  - url: ''
    description: Cloud Mock
  - url: https://api.salla.dev/admin/v2
    description: Production
security:
  - oauth21:
      - offline_access

```

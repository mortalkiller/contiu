# Contiu invoice AI in production

Contiu uses a dedicated Google Cloud service account to read invoices through Vertex AI. The Google authentication library finds that identity through Application Default Credentials (ADC), obtains a short-lived access token and refreshes it as needed. The local test used developer ADC. Configure a separate identity for the production worker.

## Google Cloud project and permissions

1. Choose the production Google Cloud project, link billing and enable the Vertex AI API (`aiplatform.googleapis.com`).
2. Create a service account for Contiu, such as `contiu-vertex-prod`. Grant it **Vertex AI User** (`roles/aiplatform.user`) on the project that receives the requests. Ensure the identity also has `serviceusage.services.use` on the quota project. Contiu uses the configured project ID for both the Vertex request and its quota header. [Vertex AI access control](https://cloud.google.com/vertex-ai/docs/general/access-control) · [Quota project permissions](https://docs.cloud.google.com/docs/quotas/set-quota-project)

## Worker identity

| Production host | ADC setup |
| --- | --- |
| Google Cloud service or VM with an attached identity | Attach the dedicated service account to the resource that runs the worker. ADC obtains the identity from the metadata service. Leave `GOOGLE_APPLICATION_CREDENTIALS` unset for this setup. [Google Cloud guide](https://docs.cloud.google.com/docs/authentication/set-up-adc-attached-service-account) |
| External host with a compatible identity provider | Configure Workload Identity Federation and allow the worker identity to impersonate the dedicated service account. Mount the generated credential configuration in the worker and set `GOOGLE_APPLICATION_CREDENTIALS` to its path inside the container. [Google Cloud guide](https://docs.cloud.google.com/iam/docs/authenticate-with-auth-libraries) |
| External host without a supported federation option | If project policy allows it, use a service account JSON key. Store it outside Git and the container image, mount it read-only, and set `GOOGLE_APPLICATION_CREDENTIALS` to its path inside the worker. Restrict file access to the worker user and plan key rotation. [Google Cloud guide](https://docs.cloud.google.com/iam/docs/migrate-from-service-account-keys) |

Set `GOOGLE_APPLICATION_CREDENTIALS`, when required, in the environment of the process that runs the job, such as the worker container or its Supervisor program. The Google library reads the process environment. The path must be readable by the worker inside its container.

## Contiu deployment

Apply these settings to the production environment when preparing the test:

```dotenv
GOOGLE_CLOUD_PROJECT_ID=your-production-project-id
GOOGLE_CLOUD_LOCATION=eu
INVOICE_AI_PROVIDER=vertex
INVOICE_AI_MODEL=gemini-3.1-flash-lite
INVOICE_AI_ENABLED=true
INVOICE_AI_LIVE_ENABLED=false
```

The release script documents `php-fpm` for the web application and `php-worker` for the scheduler. Confirm the queue setup at deployment time. With `QUEUE_CONNECTION=database`, run a supervised Laravel worker that consumes the application's queue, for example `php artisan queue:work --timeout=80`. Set the queue's `retry_after` above the 80-second job timeout. The web and worker processes need access to the same application database, private invoice storage and application key. Keep the scheduler active for document expiry. [Release script](../bin/release) · [Benchmark setup](invoice-ai-benchmark.md#local-setup)

## Production verification

1. Confirm the privacy and retention requirements before processing customer invoices. [Benchmark privacy notes](invoice-ai-benchmark.md#privacy-before-production-use)
2. For the controlled test, set `INVOICE_AI_LIVE_ENABLED=true`, refresh Laravel's cached configuration and restart the queue worker so it picks up the changes.
3. From **Teste de leitura de faturas → Analisar um documento**, upload the synthetic [sample invoice](../app/Modules/InvoiceReader/resources/fixtures/standard-qr.png) and choose **Ler com IA**. Confirm that the job finishes, the result matches the sample, and **Detalhes da análise** shows token usage and estimated cost. The gateway routes requests to `aiplatform.eu.rep.googleapis.com` with `locations/eu`.
4. If the test stays queued, check that the worker is consuming the correct queue. If authentication fails, check the worker's identity, its project permissions and the credential file path where applicable. Restore `INVOICE_AI_LIVE_ENABLED=false` after the test if production use is still pending.

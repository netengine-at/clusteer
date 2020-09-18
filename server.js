require('dotenv').config();

const express = require('express');
const { Cluster } = require('puppeteer-cluster');
const randomUserAgent = require('random-user-agent');
const app = express();
const fs = require('fs');
const URL = require('url').URL;

const options = {
  port: parseInt(process.env.PORT || 8080),
  maxConcurrency: parseInt(process.env.MAX_BROWSERS || 1),
  executablePath: process.env.CHROMIUM_PATH || '/usr/bin/google-chromne-stable',
  args: process.env.CHROMIUM_ARGS ? process.env.CHROMIUM_ARGS.split(' ') : ['--no-sandbox', '--disable-web-security'],
  ignoreHTTPSErrors: parseInt(process.env.IGNORE_HTTPS_ERRORS || 1),
  monitor: parseInt(process.env.DEBUG || 0),
  defaultTimeout: parseInt(process.env.DEFAULT_TIMEOUT || 30),
  defaultViewport: {
    width: parseInt(process.env.BROWSER_WIDTH || 800),
    height: parseInt(process.env.BROWSER_HEIGHT || 600),
    deviceScaleFactor: parseInt(process.env.DEVICE_SCALE_FACTOR || 1),
    isMobile: parseInt(process.env.IS_MOBILE || 0),
    hasTouch: parseInt(process.env.HAS_TOUCH || 0),
    isLandscape: parseInt(process.env.IS_LANDSCAPE || 0),
  }
};

app.use((err, req, res, next) => {
  next(err);
});

app.use('/healthcheck', require('express-healthcheck')());

(async () => {
  const cluster = await Cluster.launch({
    concurrency: Cluster.CONCURRENCY_CONTEXT,
    maxConcurrency: options.maxConcurrency,
    puppeteerOptions: {
      executablePath: options.executablePath,
      ignoreHTTPSErrors: options.ignoreHTTPSErrors,
      defaultViewport: {
        width: options.defaultViewport.width,
        height: options.defaultViewport.height,
        deviceScaleFactor: options.defaultViewport.deviceScaleFactor,
        isMobile: options.defaultViewport.isMobile,
        hasTouch: options.defaultViewport.hasTouch,
        isLandscape: options.defaultViewport.isLandscape,
      },
      args: options.args,
    },
    monitor: options.monitor,
  });

  // Event handler to be called in case of problems
  cluster.on('taskerror', (err, data) => {
    return res
        .status(200)
        .json({
          data: {
            status: 500,
            triggered_requests: [],
            console_lines: [],
            cookies: [],
            html: '',
            screenshot: null,
            pdf: null,
            error: err.message
          },
        });
  });


  await cluster.task(async ({ page, data: query }) => {
    const triggeredRequests = [];
    const consoleLines = [];

    const navigationPromise =  page.waitForNavigation();

    if(query.options) {
      try {
        query = JSON.parse(fs.readFileSync(query.options, 'utf8'));
      }
      catch (exception) {
        console.log(exception);
      }
    }

    //disable JavaScript on the page.
    if(query.disable_javascript) {
      await page.setJavaScriptEnabled(false);
    }

    // If ?viewport=[width]x[height] is present,
    // use the passed viewport.
    if (query.viewport) {
      const [width, height] = query.viewport.split('x');

      await page.setViewport({
        width: parseInt(width),
        height: parseInt(height),
        deviceScaleFactor: parseInt(query.device_scale_factor),
      });
    }
    else {
      // Set the viewport by default as 1920x1080
      await page.setViewport({
        width: 1920,
        height: 1080,
        deviceScaleFactor: 1,
      });
    }

    //Dialog objects are dispatched by page via the 'dialog' event.
    if (query.dismiss_dialogs) {
      page.on('dialog', async dialog => {
          await dialog.dismiss();
      });
    }

    // Set the user agent randomly, based on the device, if existent.
    // Otherwise, set it default to desktop.
    await page.setUserAgent(
      randomUserAgent(query.device ? query.device.toLowerCase() : 'desktop')
    );

    // If ?user_agent= is set, use the passed User-Agent
    if (query.user_agent) {
      await page.setUserAgent(query.user_agent);
    }

    // If extra HTTP headers are set, apply them.
    if (query.extra_headers) {
      const extra_headers = JSON.parse(query.extra_headers);
      await page.setExtraHTTPHeaders(extra_headers);
    }

    // Provide credentials for HTTP authentication.
    if (query.authentication) {
      await page.authenticate(query.authentication);
    }

    //setCookie
    if (query.cookies) {
      await page.setCookie(...query.cookies);
    }

    //This setting will change the default maximum navigation time for the following methods and related shortcuts:
    //page.goBack([options])
    //page.goForward([options])
    //page.goto(url[, options])
    //page.reload([options])
    //page.setContent(html[, options])
    //page.waitForNavigation([options])
    if (query.navigation_timeout) {
      await page.setDefaultNavigationTimeout(query.navigation_timeout); //Maximum navigation time in milliseconds
    }

    //Activating request interception enables request.abort, request.continue and request.respond methods.
    //This provides the capability to modify network requests that are made by a page.
    await page.setRequestInterception(true);

    //get console
    if (query.console_lines) {
      page.on('console', line => {
        consoleLines.push({
          type: line.type(),
          content: line.text(),
          location: line.location(),
        });
      });
    }

    //disable images
    if (query.disable_images) {
      page.on('request', request => {
          if (request.resourceType() === 'image')
              request.abort();
          else
              request.continue();
      });
    }

    // Allow to block certain extensions.
    // For example: ?blocked_extensions=.png,.jpg
    page.on('request', request => {
      if (query.blocked_extensions) {
        // Example:
        // [
        //   /\.jpg$/, /\.jpeg$/, /\.png$/, /\.gif$/,/\.css$/, /\.css\?/, /fonts/, /font/,
        // ]

        const blockedExtensions = query.blocked_extensions
          .split(',')
          .map(pattern => new RegExp(`${pattern}$`));

        let shouldBlockExtension = blockedExtensions.filter(regex => {
          return regex.test(request.url());
        }).length > 0;

        if (shouldBlockExtension) {
          return request.abort();
        }
      }

      if (query.triggered_requests) {
        triggeredRequests.push({
          type: request.resourceType(),
          method: request.method(),
          url: request.url(),
          headers: request.headers(),
          post_data: request.postData() || '',
          chain: request.redirectChain().map(req => req.url()),
          from_navigation: request.isNavigationRequest(),
        });
      }

      return request.continue();
    });

    const requestOptions = {};

    requestOptions.timeout = query.timeout ? parseInt(query.timeout) * 1000 : parseInt(options.defaultTimeout) * 1000;  //milliseconds (defaults to 30 seconds, pass 0 to disable timeout)
    requestOptions.waitUntil = query.until_idle;

    const crawledPage = await page.goto(query.url, requestOptions);


    //This method fetches an element with selector, scrolls it into view if needed, and then uses page.mouse to click in the center of the element.
    //If there's no element matching selector, the method throws an error.
    //Bear in mind that if click() triggers a navigation event and there's a separate page.waitForNavigation() promise to be resolved,
    //you may end up with a race condition that yields unexpected results.
    if (query.click) {
      const clickIt = await page.click(query.click_selector, {
            button: query.click_options['button'],
            clickCount: parseInt(query.click_options['clickCount']),
            delay: parseInt(query.click_options['delay']),
        });
      await navigationPromise;
    }

    //Adds a <link rel="stylesheet"> tag into the page with the desired url or a <style type="text/css"> tag with the content.
    if (query.add_style_tag) {
      await page.addStyleTag({
        url: (query.add_style_tag_url.length > 0 ? query.add_style_tag_url : null),
        path: (query.add_style_tag_path.length > 0 ? query.add_style_tag_path : null),
        content: query.add_style_tag_content,
      });

      await navigationPromise;
    }

    //Adds a <script> tag into the page with the desired url or content.
    if (query.add_script_tag) {
      await page.addScriptTag({
        url: (query.add_script_tag_url.length > 0 ? query.add_script_tag_url : null),
        path: (query.add_script_tag_path.length > 0 ? query.add_script_tag_path : null),
        content: query.add_script_tag_content
      });

      await navigationPromise;
    }

    // wait for selector  ==> await page.waitFor('.foo');
    // wait for 1 second  ==> await page.waitFor(1000);
    // wait for predicate ==> await page.waitFor(() => !!document.querySelector('.foo'));
    if (query.wait_for) {
      await page.waitFor(query.wait_for);
    }

    if (query.wait_for_selector) {
      try {
        await page.waitForSelector(query.wait_for_selector, { timeout: query.wait_for_selector_timeout.toFixed(2) * 1 }).then(
          //okay it worked
        );
      }
      catch (exception) {
        console.log(exception);
      }
    }

    if (query.selector) {
      const element = await page.$(query.selector);
      if (element === null) {
        throw {type: 'ElementNotFound'};
      }
      query.clip = await element.boundingBox();
    }

    //Sends a keydown, keypress/input, and keyup event for each character in the text.
    if (query.type) {
      await page.type(query.type_selector, query.type_text, { delay: parseInt(query.type_delay) });
      await navigationPromise;
    }

    if (query.function) {
      let functionOptions = {
          polling: query.functionPolling,
          timeout: query.functionTimeout || query.timeout
      };
      await page.waitForFunction(query.function, functionOptions);
    }

    const screenshot = query.screenshot ? await (async function () {
      //change "bool" string to bool
      for (const [key, value] of Object.entries(query.screenshot_options)) {
        if(value === 'false') query.screenshot_options[key] = false;
        if(value === 'true') query.screenshot_options[key] = true;
      }

      if(query.selector) {
        for (const [key, value] of Object.entries(query.clip)) {
          query.clip[key] = value.toFixed(2) * 1;
        }
        query.screenshot_options.clip = query.clip;
      }

      return await page.screenshot(query.screenshot_options);
    })() : null;

    const pdf = query.pdf ? await (async function () {
      //change "bool" string to bool
      for (const [key, value] of Object.entries(query.pdf_options)) {
        if(value === 'false') query.pdf_options[key] = false;
        if(value === 'true') query.pdf_options[key] = true;
        if(!isNaN(value)) query.pdf_options[key] = value * 1; //make value numeric
      }

      return await page.pdf(query.pdf_options);
    })() : null;


    const html = query.html ? await page.evaluate(() => document.documentElement.innerHTML) : '';

    const cookies = query.cookies ? (await page._client.send('Network.getAllCookies')).cookies : [];

    return {
      status: crawledPage.status(),
      triggered_requests: triggeredRequests,
      console_lines: consoleLines,
      cookies,
      html,
      screenshot,
      pdf,
      error: null
    }
  });


  app.get('/', async (req, res) => {
    try {
      const data = await cluster.execute(req.query);

      //convert pdf binary to base64
      if(data.pdf) data.pdf = data.pdf.toString('base64');

      return res.status(200).json({ data });
    } catch (err) {
      return res
        .status(200)
        .json({
          data: {
            status: 500,
            triggered_requests: [],
            console_lines: [],
            cookies: [],
            html: '',
            screenshot: null,
            pdf: null,
            error: err.message
          },
        });
    }
  });

  const server = app.listen(options.port, () => {
    console.log(`Clusteer server running on port ${options.port}.`)
    //console.log(`Options: `, options);
  });

  // Make sure the app responds to SIGTERM and SIGINT so
  // it closes the node server.js process.
  process.on('SIGTERM', () => {
    server.close();
    process.exit();
  });

  process.on('SIGINT', () => {
    server.close();
    process.exit();
  });
})();
